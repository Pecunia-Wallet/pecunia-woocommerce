<?php
declare(strict_types=1);

namespace GuzzleHttp\Exception;

use RuntimeException;

class RequestException extends RuntimeException implements GuzzleException {}
