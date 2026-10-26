<?php

declare(strict_types=1);

namespace Dskripchenko\ProcessEngine\Definition;

enum StateType: string
{
    case Atomic = 'atomic';
    case Compound = 'compound';
    case Parallel = 'parallel';
    case Final = 'final';
    case Choice = 'choice';
    case History = 'history';
}
