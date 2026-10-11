<?php

declare(strict_types=1);

namespace Phox\ContextAtlas;

enum BlockType: string
{
    case Heading = 'heading';
    case Paragraph = 'paragraph';
    case Table = 'table';
    case Code = 'code';
}
