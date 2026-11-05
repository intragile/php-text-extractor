<?php

declare(strict_types=1);

namespace IntrAgile\TextExtractor\Exception;

use RuntimeException;

/**
 * The input is damaged or structurally invalid for its format; retrying will not help.
 */
class CorruptInput extends RuntimeException {}
