<?php
namespace Psalm\Checker\Statements\Expression;

use PhpParser;
use Psalm\Checker\ClassChecker;
use Psalm\Checker\ClassLikeChecker;
use Psalm\Checker\ClosureChecker;
use Psalm\Checker\CommentChecker;
use Psalm\Checker\FunctionChecker;
use Psalm\Checker\FunctionLikeChecker;
use Psalm\Checker\InterfaceChecker;
use Psalm\Checker\MethodChecker;
use Psalm\Checker\StatementsChecker;
use Psalm\Checker\Statements\ExpressionChecker;
use Psalm\Checker\TraitChecker;
use Psalm\Checker\TypeChecker;
use Psalm\Issue\ForbiddenCode;
use Psalm\Issue\InvalidArgument;
use Psalm\Issue\InvalidScalarArgument;
use Psalm\Issue\InvalidScope;
use Psalm\Issue\MixedArgument;
use Psalm\Issue\MixedMethodCall;
use Psalm\Issue\NullReference;
use Psalm\Issue\ParentNotFound;
use Psalm\Issue\TooFewArguments;
use Psalm\Issue\TooManyArguments;
use Psalm\Issue\TypeCoercion;
use Psalm\Issue\UndefinedFunction;
use Psalm\Config;
use Psalm\Context;
use Psalm\IssueBuffer;
use Psalm\Type;

class CallChecker
{
    /**
     * @param  PhpParser\Node\Expr\FuncCall $stmt
     * @param  Context                      $context
     * @return false|null
     */
    public static function checkFunctionCall(StatementsChecker $statements_checker, PhpParser\Node\Expr\FuncCall $stmt, Context $context)
    {
        $method = $stmt->name;

        if ($method instanceof PhpParser\Node\Name) {
            $first_arg = isset($stmt->args[0]) ? $stmt->args[0] : null;

            if ($method->parts === ['method_exists']) {
                $context->check_methods = false;

            }
            elseif ($method->parts === ['class_exists']) {
                if ($first_arg && $first_arg->value instanceof PhpParser\Node\Scalar\String_) {
                    $context->addPhantomClass($first_arg->value->value);
                }
                else {
                    $context->check_classes = false;
                }

            }
            elseif ($method->parts === ['function_exists']) {
                $context->check_functions = false;

            }
            elseif ($method->parts === ['is_callable']) {
                $context->check_methods = false;
                $context->check_functions = false;
            }
            elseif ($method->parts === ['defined']) {
                $context->check_consts = false;

            }
            elseif ($method->parts === ['extract']) {
                $context->check_variables = false;

            }
            elseif ($method->parts === ['var_dump'] || $method->parts === ['die'] || $method->parts === ['exit']) {
                if (IssueBuffer::accepts(
                    new ForbiddenCode('Unsafe ' . implode('', $method->parts), $statements_checker->getCheckedFileName(), $stmt->getLine()),
                    $statements_checker->getSuppressedIssues()
                )) {
                    return false;
                }
            }
            elseif ($method->parts === ['define']) {
                if ($first_arg && $first_arg->value instanceof PhpParser\Node\Scalar\String_) {
                    $second_arg = $stmt->args[1];
                    ExpressionChecker::check($statements_checker, $second_arg->value, $context);
                    $const_name = $first_arg->value->value;

                    $statements_checker->setConstType(
                        $const_name,
                        isset($second_arg->value->inferredType) ? $second_arg->value->inferredType : Type::getMixed()
                    );
                }
                else {
                    $context->check_consts = false;
                }
            }
        }

        $method_id = null;

        if ($context->check_functions) {
            if (!($stmt->name instanceof PhpParser\Node\Name)) {
                return;
            }

            $method_id = implode('', $stmt->name->parts);

            if ($context->self) {
                //$method_id = $statements_checker->getAbsoluteClass() . '::' . $method_id;
            }

            $in_call_map = FunctionChecker::inCallMap($method_id);

            if (!$in_call_map && self::checkFunctionExists($statements_checker, $method_id, $context, $stmt->getLine()) === false) {
                return false;
            }

            if (self::checkFunctionArguments($statements_checker, $stmt->args, $method_id, $context, $stmt->getLine()) === false) {
                return false;
            }

            if ($in_call_map) {
                $stmt->inferredType = FunctionChecker::getReturnTypeFromCallMapWithArgs(
                    $method_id,
                    $stmt->args,
                    $statements_checker->getCheckedFileName(),
                    $stmt->getLine(),
                    $statements_checker->getSuppressedIssues()
                );
            }
            else {
                try {
                    $stmt->inferredType = FunctionChecker::getFunctionReturnTypes($method_id, $statements_checker->getCheckedFileName());
                }
                catch (\InvalidArgumentException $e) {
                    // this can happen when the function was defined in the Config startup script
                    $stmt->inferredType = Type::getMixed();
                }
            }
        }

        if ($stmt->name instanceof PhpParser\Node\Name && $stmt->name->parts === ['get_class'] && $stmt->args) {
            $var = $stmt->args[0]->value;

            if ($var instanceof PhpParser\Node\Expr\Variable && is_string($var->name)) {
                $stmt->inferredType = new Type\Union([new Type\T('$' . $var->name)]);
            }
        }
    }

    /**
     * @return false|null
     */
    public static function checkNew(
        StatementsChecker $statements_checker,
        PhpParser\Node\Expr\New_ $stmt,
        Context $context
    ) {
        $absolute_class = null;

        if ($stmt->class instanceof PhpParser\Node\Name) {
            if (!in_array($stmt->class->parts[0], ['self', 'static', 'parent'])) {
                if ($context->check_classes) {
                    $absolute_class = ClassLikeChecker::getAbsoluteClassFromName($stmt->class, $statements_checker->getNamespace(), $statements_checker->getAliasedClasses());

                    if ($context->isPhantomClass($absolute_class)) {
                        return;
                    }

                    if (ClassLikeChecker::checkAbsoluteClassOrInterface($absolute_class, $statements_checker->getCheckedFileName(), $stmt->getLine(), $statements_checker->getSuppressedIssues()) === false) {
                        return false;
                    }
                }
            }
            else {
                switch ($stmt->class->parts[0]) {
                    case 'self':
                        $absolute_class = $context->self;
                        break;

                    case 'parent':
                        $absolute_class = $context->parent;
                        break;

                    case 'static':
                        // @todo maybe we can do better here
                        $absolute_class = $context->self;
                        break;
                }
            }
        }
        elseif ($stmt->class instanceof PhpParser\Node\Stmt\Class_) {
            $statements_checker->check([$stmt->class], $context);
            $absolute_class = $stmt->class->name;
        }
        else {
            ExpressionChecker::check($statements_checker, $stmt->class, $context);
        }

        if ($absolute_class) {
            $stmt->inferredType = new Type\Union([new Type\Atomic($absolute_class)]);

            if (MethodChecker::methodExists($absolute_class . '::__construct')) {
                $method_id = $absolute_class . '::__construct';

                if (self::checkFunctionArguments($statements_checker, $stmt->args, $method_id, $context, $stmt->getLine()) === false) {
                    return false;
                }

                if ($absolute_class === 'ArrayIterator' && isset($stmt->args[0]->value->inferredType)) {
                    /** @var Type\Union */
                    $first_arg_type = $stmt->args[0]->value->inferredType;

                    if ($first_arg_type->hasGeneric()) {
                        /** @var Type\Union|null */
                        $key_type = null;

                        /** @var Type\Union|null */
                        $value_type = null;

                        foreach ($first_arg_type->types as $type) {
                            if ($type instanceof Type\Generic) {
                                $first_type_param = count($type->type_params) ? $type->type_params[0] : null;
                                $last_type_param = $type->type_params[count($type->type_params) - 1];

                                if ($value_type === null) {
                                    $value_type = clone $last_type_param;
                                }
                                else {
                                    $value_type = Type::combineUnionTypes($value_type, $last_type_param);
                                }

                                if (!$key_type || !$first_type_param) {
                                    $key_type = $first_type_param ? clone $first_type_param : Type::getMixed();
                                }
                                else {
                                    $key_type = Type::combineUnionTypes($key_type, $first_type_param);
                                }
                            }
                        }

                        $stmt->inferredType = new Type\Union([
                            new Type\Generic(
                                $absolute_class,
                                [
                                    $key_type,
                                    $value_type
                                ]
                            )
                        ]);

                    }
                }
            }
        }
    }

    /**
     * @return false|null
     */
    public static function checkMethodCall(StatementsChecker $statements_checker, PhpParser\Node\Expr\MethodCall $stmt, Context $context)
    {
        if (ExpressionChecker::check($statements_checker, $stmt->var, $context) === false) {
            return false;
        }

        $class_type = null;
        $method_id = null;

        if ($stmt->var instanceof PhpParser\Node\Expr\Variable) {
            if (is_string($stmt->var->name) && $stmt->var->name === 'this' && !$statements_checker->getClassName()) {
                if (IssueBuffer::accepts(
                    new InvalidScope('Use of $this in non-class context', $statements_checker->getCheckedFileName(), $stmt->getLine()),
                    $statements_checker->getSuppressedIssues()
                )) {
                    return false;
                }
            }
        }

        $var_id = ExpressionChecker::getVarId($stmt->var, $statements_checker->getAbsoluteClass(), $statements_checker->getNamespace(), $statements_checker->getAliasedClasses());

        $class_type = isset($context->vars_in_scope[$var_id]) ? $context->vars_in_scope[$var_id] : null;

        if (isset($stmt->var->inferredType)) {
            /** @var Type\Union */
            $class_type = $stmt->var->inferredType;
        }
        elseif (!$class_type) {
            $stmt->inferredType = Type::getMixed();
        }

        $source = $statements_checker->getSource();

        if ($stmt->var instanceof PhpParser\Node\Expr\Variable
            && $stmt->var->name === 'this'
            && is_string($stmt->name)
            && $source instanceof FunctionLikeChecker
        ) {
            $this_method_id = $source->getMethodId();

            if (($this_class = ClassLikeChecker::getThisClass()) &&
                (
                    $this_class === $statements_checker->getAbsoluteClass() ||
                    ClassChecker::classExtends($this_class, $statements_checker->getAbsoluteClass()) ||
                    TraitChecker::traitExists($statements_checker->getAbsoluteClass())
                )) {

                $method_id = $statements_checker->getAbsoluteClass() . '::' . strtolower($stmt->name);

                if ($statements_checker->checkInsideMethod($method_id, $context) === false) {
                    return false;
                }
            }
        }

        if (!$context->check_methods || !$context->check_classes) {
            return;
        }

        $has_mock = false;

        if ($class_type && is_string($stmt->name)) {
            /** @var Type\Union|null */
            $return_type = null;

            foreach ($class_type->types as $type) {
                $absolute_class = $type->value;

                $is_mock = ExpressionChecker::isMock($absolute_class);

                $has_mock = $has_mock || $is_mock;

                switch ($absolute_class) {
                    case 'null':
                        if (IssueBuffer::accepts(
                            new NullReference(
                                'Cannot call method ' . $stmt->name . ' on possibly null variable ' . $var_id,
                                $statements_checker->getCheckedFileName(),
                                $stmt->getLine()
                            ),
                            $statements_checker->getSuppressedIssues()
                        )) {
                            return false;
                        }
                        break;

                    case 'int':
                    case 'bool':
                    case 'false':
                    case 'array':
                    case 'string':
                        if (IssueBuffer::accepts(
                            new InvalidArgument(
                                'Cannot call method ' . $stmt->name . ' on ' . $class_type . ' variable ' . $var_id,
                                $statements_checker->getCheckedFileName(),
                                $stmt->getLine()
                            ),
                            $statements_checker->getSuppressedIssues()
                        )) {
                            return false;
                        }
                        break;

                    case 'mixed':
                    case 'object':
                        if (IssueBuffer::accepts(
                            new MixedMethodCall(
                                'Cannot call method ' . $stmt->name . ' on a mixed variable ' . $var_id,
                                $statements_checker->getCheckedFileName(),
                                $stmt->getLine()
                            ),
                            $statements_checker->getSuppressedIssues()
                        )) {
                            return false;
                        }
                        break;

                    case 'static':
                        $absolute_class = (string) $context->self;
                        // fall through to default

                    default:
                        if (MethodChecker::methodExists($absolute_class . '::__call') || $is_mock || $context->isPhantomClass($absolute_class)) {
                            $return_type = Type::getMixed();
                            continue;
                        }

                        $does_class_exist = ClassLikeChecker::checkAbsoluteClassOrInterface(
                            $absolute_class,
                            $statements_checker->getCheckedFileName(),
                            $stmt->getLine(),
                            $statements_checker->getSuppressedIssues()
                        );

                        if (!$does_class_exist) {
                            return $does_class_exist;
                        }

                        $method_id = $absolute_class . '::' . strtolower($stmt->name);
                        $cased_method_id = $absolute_class . '::' . $stmt->name;

                        $does_method_exist = MethodChecker::checkMethodExists($cased_method_id, $statements_checker->getCheckedFileName(), $stmt->getLine(), $statements_checker->getSuppressedIssues());

                        if (!$does_method_exist) {
                            return $does_method_exist;
                        }

                        if (FunctionChecker::inCallMap($cased_method_id)) {
                            $return_type_candidate = FunctionChecker::getReturnTypeFromCallMap($method_id);
                        }
                        else {
                            if (MethodChecker::checkMethodVisibility($method_id, $context->self, $statements_checker->getSource(), $stmt->getLine(), $statements_checker->getSuppressedIssues()) === false) {
                                return false;
                            }

                            if (MethodChecker::checkMethodNotDeprecated($method_id, $statements_checker->getCheckedFileName(), $stmt->getLine(), $statements_checker->getSuppressedIssues()) === false) {
                                return false;
                            }

                            $return_type_candidate = MethodChecker::getMethodReturnTypes($method_id);
                        }

                        if ($return_type_candidate) {
                            $return_type_candidate = ExpressionChecker::fleshOutTypes($return_type_candidate, $stmt->args, $absolute_class, $method_id);

                            if (!$return_type) {
                                $return_type = $return_type_candidate;
                            }
                            else {
                                $return_type = Type::combineUnionTypes($return_type_candidate, $return_type);
                            }
                        }
                        else {
                            $return_type = Type::getMixed();
                        }
                }
            }

            $stmt->inferredType = $return_type;
        }

        if (self::checkFunctionArguments($statements_checker, $stmt->args, $method_id, $context, $stmt->getLine(), $has_mock) === false) {
            return false;
        }
    }

    /**
     * @return false|null
     */
    public static function checkStaticCall(StatementsChecker $statements_checker, PhpParser\Node\Expr\StaticCall $stmt, Context $context)
    {
        if ($stmt->class instanceof PhpParser\Node\Expr\Variable || $stmt->class instanceof PhpParser\Node\Expr\ArrayDimFetch) {
            // this is when calling $some_class::staticMethod() - which is a shitty way of doing things
            // because it can't be statically type-checked
            return;
        }

        $method_id = null;
        $absolute_class = null;

        $lhs_type = null;

        if ($stmt->class instanceof PhpParser\Node\Name) {
            $absolute_class = null;

            if (count($stmt->class->parts) === 1 && in_array($stmt->class->parts[0], ['self', 'static', 'parent'])) {
                if ($stmt->class->parts[0] === 'parent') {
                    if ($statements_checker->getParentClass() === null) {
                        if (IssueBuffer::accepts(
                            new ParentNotFound('Cannot call method on parent as this class does not extend another', $statements_checker->getCheckedFileName(), $stmt->getLine()),
                            $statements_checker->getSuppressedIssues()
                        )) {
                            return false;
                        }
                    }

                    $absolute_class = $statements_checker->getParentClass();
                } else {
                    $absolute_class = ($statements_checker->getNamespace() ? $statements_checker->getNamespace() . '\\' : '') . $statements_checker->getClassName();
                }

                if ($context->isPhantomClass($absolute_class)) {
                    return;
                }
            }
            elseif ($context->check_classes) {
                $absolute_class = ClassLikeChecker::getAbsoluteClassFromName(
                    $stmt->class,
                    $statements_checker->getNamespace(),
                    $statements_checker->getAliasedClasses()
                );

                if ($context->isPhantomClass($absolute_class)) {
                    return;
                }

                $does_class_exist = ClassLikeChecker::checkAbsoluteClassOrInterface(
                    $absolute_class,
                    $statements_checker->getCheckedFileName(),
                    $stmt->getLine(),
                    $statements_checker->getSuppressedIssues()
                );

                if (!$does_class_exist) {
                    return $does_class_exist;
                }
            }

            if ($stmt->class->parts === ['parent'] && is_string($stmt->name)) {
                if (ClassLikeChecker::getThisClass()) {
                    $method_id = $absolute_class . '::' . strtolower($stmt->name);

                    if ($statements_checker->checkInsideMethod($method_id, $context) === false) {
                        return false;
                    }
                }
            }

            if ($absolute_class) {
                $lhs_type = new Type\Union([new Type\Atomic($absolute_class)]);
            }
        }
        else {
            ExpressionChecker::check($statements_checker, $stmt->class, $context);

            /** @var Type\Union */
            $lhs_type = $stmt->class->inferredType;
        }

        if (!$context->check_methods || !$lhs_type) {
            return;
        }

        $has_mock = false;

        foreach ($lhs_type->types as $lhs_type_part) {
            $absolute_class = $lhs_type_part->value;

            $is_mock = ExpressionChecker::isMock($absolute_class);

            $has_mock = $has_mock || $is_mock;

            if (is_string($stmt->name) && !MethodChecker::methodExists($absolute_class . '::__callStatic') && !$is_mock) {
                $method_id = $absolute_class . '::' . strtolower($stmt->name);
                $cased_method_id = $absolute_class . '::' . $stmt->name;

                $does_method_exist = MethodChecker::checkMethodExists($cased_method_id, $statements_checker->getCheckedFileName(), $stmt->getLine(), $statements_checker->getSuppressedIssues());

                if (!$does_method_exist) {
                    return $does_method_exist;
                }

                if (MethodChecker::checkMethodVisibility($method_id, $context->self, $statements_checker->getSource(), $stmt->getLine(), $statements_checker->getSuppressedIssues()) === false) {
                    return false;
                }

                if ($stmt->class instanceof PhpParser\Node\Name
                    && $stmt->class->parts[0] !== 'parent'
                    && $context->self
                    && ($statements_checker->isStatic() || !ClassChecker::classExtends($context->self, $absolute_class))
                ) {
                    if (MethodChecker::checkMethodStatic($method_id, $statements_checker->getCheckedFileName(), $stmt->getLine(), $statements_checker->getSuppressedIssues()) === false) {
                        return false;
                    }
                }

                if (MethodChecker::checkMethodNotDeprecated($method_id, $statements_checker->getCheckedFileName(), $stmt->getLine(), $statements_checker->getSuppressedIssues()) === false) {
                    return false;
                }

                $return_types = MethodChecker::getMethodReturnTypes($method_id);

                if ($return_types) {
                    $return_types = ExpressionChecker::fleshOutTypes(
                        $return_types,
                        $stmt->args,
                        $stmt->class instanceof PhpParser\Node\Name && $stmt->class->parts === ['parent']
                            ? $statements_checker->getAbsoluteClass()
                            : $absolute_class,
                        $method_id
                    );

                    if (isset($stmt->inferredType)) {
                        $stmt->inferredType = Type::combineUnionTypes($stmt->inferredType, $return_types);
                    }
                    else {
                        $stmt->inferredType = $return_types;
                    }
                }
            }

            if (self::checkFunctionArguments($statements_checker, $stmt->args, $method_id, $context, $stmt->getLine(), $has_mock) === false) {
                return false;
            }
        }

        return;
    }

    /**
     * @param  array<int, PhpParser\Node\Arg>   $args
     * @param  string|null                      $method_id
     * @param  Context                          $context
     * @param  int                              $line_number
     * @param  boolean                          $is_mock
     * @return false|null
     */
    protected static function checkFunctionArguments(StatementsChecker $statements_checker, array $args, $method_id, Context $context, $line_number, $is_mock = false)
    {
        $function_params = null;

        $is_variadic = false;

        $absolute_class = null;

        $in_call_map = FunctionChecker::inCallMap($method_id);

        if ($method_id) {
            $function_params = FunctionLikeChecker::getParamsById($method_id, $args, $statements_checker->getFileName());

            if ($in_call_map || !strpos($method_id, '::')) {
                $is_variadic = FunctionChecker::isVariadic(strtolower($method_id), $statements_checker->getFileName());
            }
            else {
                $absolute_class = explode('::', $method_id)[0];
                $is_variadic = $is_mock || MethodChecker::isVariadic($method_id);
            }
        }

        foreach ($args as $argument_offset => $arg) {
            if ($arg->value instanceof PhpParser\Node\Expr\PropertyFetch) {
                if ($method_id) {
                    $by_ref = false;
                    $by_ref_type = null;

                    if ($function_params) {
                        $by_ref = $argument_offset < count($function_params) && $function_params[$argument_offset]->by_ref;
                        $by_ref_type = $by_ref && $argument_offset < count($function_params) ? clone $function_params[$argument_offset]->type : null;
                    }

                    if ($by_ref && $by_ref_type) {
                        ExpressionChecker::assignByRefParam($statements_checker, $arg->value, $by_ref_type, $context);
                    }
                    else {
                        if (FetchChecker::checkPropertyFetch($statements_checker, $arg->value, $context) === false) {
                            return false;
                        }
                    }
                }
                else {
                    $var_id = ExpressionChecker::getVarId($arg->value, $statements_checker->getAbsoluteClass(), $statements_checker->getNamespace(), $statements_checker->getAliasedClasses());

                    if ($var_id && (!isset($context->vars_in_scope[$var_id]) || $context->vars_in_scope[$var_id]->isNull())) {
                        // we don't know if it exists, assume it's passed by reference
                        $context->vars_in_scope[$var_id] = Type::getMixed();
                        $context->vars_possibly_in_scope[$var_id] = true;
                        $statements_checker->registerVariable('$' . $var_id, $arg->value->getLine());
                    }
                }
            }
            elseif ($arg->value instanceof PhpParser\Node\Expr\Variable) {
                if ($method_id) {
                    $by_ref = false;
                    $by_ref_type = null;

                    if ($function_params) {
                        $by_ref = $argument_offset < count($function_params) && $function_params[$argument_offset]->by_ref;
                        $by_ref_type = $by_ref && $argument_offset < count($function_params) ? clone $function_params[$argument_offset]->type : null;
                    }

                    if (ExpressionChecker::checkVariable($statements_checker, $arg->value, $context, $by_ref, $by_ref_type) === false) {
                        return false;
                    }

                } elseif (is_string($arg->value->name)) {
                    if (false || !isset($context->vars_in_scope['$' . $arg->value->name]) || $context->vars_in_scope['$' . $arg->value->name]->isNull()) {
                        // we don't know if it exists, assume it's passed by reference
                        $context->vars_in_scope['$' . $arg->value->name] = Type::getMixed();
                        $context->vars_possibly_in_scope['$' . $arg->value->name] = true;
                        $statements_checker->registerVariable('$' . $arg->value->name, $arg->value->getLine());
                    }
                }
            }
            else {
                if (ExpressionChecker::check($statements_checker, $arg->value, $context) === false) {
                    return false;
                }
            }
        }

        // we need to do this calculation after the above vars have already processed
        $function_params = $method_id ? FunctionLikeChecker::getParamsById($method_id, $args, $statements_checker->getFileName()) : [];

        $cased_method_id = $method_id;

        if ($method_id && strpos($method_id, '::') && !$in_call_map) {
            $cased_method_id = MethodChecker::getCasedMethodId($method_id);
        }

        if ($function_params) {
            foreach ($function_params as $function_param) {
                $is_variadic = $is_variadic || $function_param->is_variadic;
            }
        }

        $has_packed_var = false;

        foreach ($args as $arg) {
            $has_packed_var = $has_packed_var || $arg->unpack;
        }

        foreach ($args as $argument_offset => $arg) {
            if ($method_id && $cased_method_id && isset($arg->value->inferredType)) {
                if (count($function_params) > $argument_offset) {
                    $param_type = $function_params[$argument_offset]->type;

                    // for now stop when we encounter a variadic param pr a packed argument
                    if ($function_params[$argument_offset]->is_variadic || $arg->unpack) {
                        break;
                    }

                    if (self::checkFunctionArgumentType(
                        $statements_checker,
                        $arg->value->inferredType,
                        ExpressionChecker::fleshOutTypes(
                            clone $param_type,
                            [],
                            $absolute_class,
                            $method_id
                        ),
                        $cased_method_id,
                        $argument_offset,
                        $arg->value->getLine()
                    ) === false
                    ) {
                        return false;
                    }
                }
            }
        }

        if ($method_id === 'array_map' || $method_id === 'array_filter') {
            $closure_index = $method_id === 'array_map' ? 0 : 1;

            $array_arg_types = [];

            foreach ($args as $i => $arg) {
                if ($i === 0 && $method_id === 'array_map') {
                    continue;
                }

                if ($i === 1 && $method_id === 'array_filter') {
                    break;
                }

                $array_arg = isset($args[$i]->value) ? $args[$i]->value : null;

                $array_arg_types[] = $array_arg
                        && isset($array_arg->inferredType)
                        && isset($array_arg->inferredType->types['array'])
                        && $array_arg->inferredType->types['array'] instanceof Type\Generic
                    ? $array_arg->inferredType->types['array']
                    : null;
            }

            /** @var PhpParser\Node\Expr\Closure|null */
            $closure_arg = isset($args[$closure_index]) && $args[$closure_index]->value instanceof PhpParser\Node\Expr\Closure
                            ? $args[$closure_index]->value
                            : null;

            if ($closure_arg) {
                $expected_closure_param_count = $method_id === 'array_filter' ? 1 : count($array_arg_types);

                if (count($closure_arg->params) > $expected_closure_param_count) {
                    if (IssueBuffer::accepts(
                        new TooManyArguments(
                            'Too many arguments in closure for ' . ($cased_method_id ?: $method_id),
                            $statements_checker->getCheckedFileName(),
                            $closure_arg->getLine()
                        ),
                        $statements_checker->getSuppressedIssues()
                    )) {
                        return false;
                    }
                }
                elseif (count($closure_arg->params) < $expected_closure_param_count) {
                    if (IssueBuffer::accepts(
                        new TooFewArguments(
                            'You must supply a param in the closure for ' . ($cased_method_id ?: $method_id),
                            $statements_checker->getCheckedFileName(),
                            $closure_arg->getLine()
                        ),
                        $statements_checker->getSuppressedIssues()
                    )) {
                        return false;
                    }
                }

                foreach ($closure_arg->params as $i => $closure_param) {
                    if (!$array_arg_types[$i]) {
                        continue;
                    }

                    /** @var Type\Generic */
                    $array_arg_type = $array_arg_types[$i];

                    $translated_param = FunctionLikeChecker::getTranslatedParam(
                        $closure_param,
                        $statements_checker->getAbsoluteClass(),
                        $statements_checker->getNamespace(),
                        $statements_checker->getAliasedClasses()
                    );

                    $param_type = $translated_param->type;
                    $input_type = $array_arg_type->type_params[1];

                    if ($input_type->isMixed()) {
                        continue;
                    }

                    $type_match_found = FunctionLikeChecker::doesParamMatch($input_type, $param_type, $scalar_type_match_found, $coerced_type);

                    if ($coerced_type) {
                        if (IssueBuffer::accepts(
                            new TypeCoercion(
                                'First parameter of closure passed to function ' . $cased_method_id . ' expects ' . $param_type . ', parent type ' . $input_type . ' provided',
                                $statements_checker->getCheckedFileName(),
                                $closure_param->getLine()
                            ),
                            $statements_checker->getSuppressedIssues()
                        )) {
                            return false;
                        }
                    }

                    if (!$type_match_found) {
                        if ($scalar_type_match_found) {
                            if (IssueBuffer::accepts(
                                new InvalidScalarArgument(
                                    'First parameter of closure passed to function ' . $cased_method_id . ' expects ' . $param_type . ', ' . $input_type . ' provided',
                                    $statements_checker->getCheckedFileName(),
                                    $closure_param->getLine()
                                ),
                                $statements_checker->getSuppressedIssues()
                            )) {
                                return false;
                            }
                        }
                        else if (IssueBuffer::accepts(
                            new InvalidArgument(
                                'First parameter of closure passed to function ' . $cased_method_id . ' expects ' . $param_type . ', ' . $input_type . ' provided',
                                $statements_checker->getCheckedFileName(),
                                $closure_param->getLine()
                            ),
                            $statements_checker->getSuppressedIssues()
                        )) {
                            return false;
                        }
                    }
                }
            }
        }

        if ($method_id) {
            if (!$is_variadic
                && count($args) > count($function_params)
                && (!count($function_params) || $function_params[count($function_params) - 1]->name !== '...=')
            ) {
                if (IssueBuffer::accepts(
                    new TooManyArguments(
                        'Too many arguments for method ' . ($cased_method_id ?: $method_id),
                        $statements_checker->getCheckedFileName(),
                        $line_number
                    ),
                    $statements_checker->getSuppressedIssues()
                )) {
                    return false;
                }

                return;
            }

            if (!$has_packed_var && count($args) < count($function_params)) {
                for ($i = count($args); $i < count($function_params); $i++) {
                    $param = $function_params[$i];

                    if (!$param->is_optional && !$param->is_variadic) {
                        if (IssueBuffer::accepts(
                            new TooFewArguments('Too few arguments for method ' . $cased_method_id, $statements_checker->getCheckedFileName(), $line_number),
                            $statements_checker->getSuppressedIssues()
                        )) {
                            return false;
                        }

                        break;
                    }
                }
            }
        }
    }

    /**
     * @param  Type\Union $input_type
     * @param  Type\Union $param_type
     * @param  string     $cased_method_id
     * @param  int        $argument_offset
     * @param  int        $line_number
     * @return null|false
     */
    protected static function checkFunctionArgumentType(
        StatementsChecker $statements_checker,
        Type\Union $input_type,
        Type\Union $param_type,
        $cased_method_id,
        $argument_offset,
        $line_number
    ) {
        if ($param_type->isMixed()) {
            return;
        }

        if ($input_type->isMixed()) {
            if (IssueBuffer::accepts(
                new MixedArgument(
                    'Argument ' . ($argument_offset + 1) . ' of ' . $cased_method_id . ' cannot be mixed, expecting ' . $param_type,
                    $statements_checker->getCheckedFileName(),
                    $line_number
                ),
                $statements_checker->getSuppressedIssues()
            )) {
                return false;
            }

            return;
        }

        if ($input_type->isNullable() && !$param_type->isNullable()) {
            if (IssueBuffer::accepts(
                new NullReference(
                    'Argument ' . ($argument_offset + 1) . ' of ' . $cased_method_id . ' cannot be null, possibly null value provided',
                    $statements_checker->getCheckedFileName(),
                    $line_number
                ),
                $statements_checker->getSuppressedIssues()
            )) {
                return false;
            }
        }

        $type_match_found = FunctionLikeChecker::doesParamMatch($input_type, $param_type, $scalar_type_match_found, $coerced_type);

        if ($coerced_type) {
            if (IssueBuffer::accepts(
                new TypeCoercion(
                    'Argument ' . ($argument_offset + 1) . ' of ' . $cased_method_id . ' expects ' . $param_type . ', parent type ' . $input_type . ' provided',
                    $statements_checker->getCheckedFileName(),
                    $line_number
                ),
                $statements_checker->getSuppressedIssues()
            )) {
                return false;
            }
        }

        if (!$type_match_found) {
            if ($scalar_type_match_found) {
                if (IssueBuffer::accepts(
                    new InvalidScalarArgument(
                        'Argument ' . ($argument_offset + 1) . ' of ' . $cased_method_id . ' expects ' . $param_type . ', ' . $input_type . ' provided',
                        $statements_checker->getCheckedFileName(),
                        $line_number
                    ),
                    $statements_checker->getSuppressedIssues()
                )) {
                    return false;
                }
            }
            else if (IssueBuffer::accepts(
                new InvalidArgument(
                    'Argument ' . ($argument_offset + 1) . ' of ' . $cased_method_id . ' expects ' . $param_type . ', ' . $input_type . ' provided',
                    $statements_checker->getCheckedFileName(),
                    $line_number
                ),
                $statements_checker->getSuppressedIssues()
            )) {
                return false;
            }
        }
    }

    /**
     * @param  StatementsChecker    $statements_checker
     * @param  string               $function_id
     * @param  Context              $context
     * @param  int                  $line_number
     * @return bool
     */
    protected static function checkFunctionExists(StatementsChecker $statements_checker, $function_id, Context $context, $line_number)
    {
        $cased_function_id = $function_id;
        $function_id = strtolower($function_id);

        if (!FunctionChecker::functionExists($function_id, $context->file_name)) {
            if (IssueBuffer::accepts(
                new UndefinedFunction(
                    'Function ' . $cased_function_id . ' does not exist',
                    $statements_checker->getCheckedFileName(),
                    $line_number
                ),
                $statements_checker->getSuppressedIssues()
            )) {
                return false;
            }
        }

        return true;
    }
}