<?php

declare(strict_types=1);

/**
 * This file is part of Larastan.
 *
 * (c) Nuno Maduro <enunomaduro@gmail.com>
 *
 *  For the full copyright and license information, please view the LICENSE
 *  file that was distributed with this source code.
 */

namespace NunoMaduro\Larastan\ReturnTypes\Helpers;

use PHPStan\Type\Type;
use PHPStan\Analyser\Scope;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;

class TransExtension implements DynamicFunctionReturnTypeExtension
{
    /**
     * {@inheritdoc}
     */
    public function isFunctionSupported(FunctionReflection $functionReflection): bool
    {
        return $functionReflection->getName() === 'trans';
    }

    /**
     * {@inheritdoc}
     */
    public function getTypeFromFunctionCall(
        FunctionReflection $functionReflection,
        FuncCall $functionCall,
        Scope $scope
    ): Type {
        // No path provided, so it returns a Translator instance
        if (count($functionCall->args) === 0) {
            return new ObjectType(\Illuminate\Contracts\Translation\Translator::class);
        }

        return new MixedType();
    }
}
