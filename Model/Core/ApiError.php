<?php
/**
 * Copyright © CoinsPay. All rights reserved.
 * See LICENSE.txt for license details (OSL 3.0).
 */
declare(strict_types=1);

namespace CoinsPay\Payment\Model\Core;

/**
 * An error of the CoinsPay API or of the way to it.
 */
class ApiError extends \RuntimeException
{
    /** @var int HTTP status; 0 when no answer came. */
    private $status;

    /** @var string Stable error code of the API, e.g. VALIDATION_ERROR, or NETWORK. */
    private $errorCode;

    /** @var array Error details: invalid fields and the like. */
    private $details;

    /**
     * @param int $status HTTP status, 0 without an answer.
     * @param string $errorCode API error code.
     * @param string $message Explanation in English.
     * @param array $details Error details.
     */
    public function __construct(int $status, string $errorCode, string $message, array $details = [])
    {
        parent::__construct($message !== '' ? $message : $errorCode);
        $this->status = $status;
        $this->errorCode = $errorCode;
        $this->details = $details;
    }

    /**
     * HTTP status; 0 when no answer came.
     *
     * @return int
     */
    public function status(): int
    {
        return $this->status;
    }

    /**
     * Error code of the API.
     *
     * @return string
     */
    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Error details.
     *
     * @return array
     */
    public function details(): array
    {
        return $this->details;
    }

    /**
     * The invoice does not exist for this key: another shop, the other mode or a sample event.
     *
     * @return bool
     */
    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    /**
     * One line for the log: code, message and details, never the key.
     *
     * @return string
     */
    public function summary(): string
    {
        $line = $this->errorCode . ': ' . $this->getMessage();
        if ($this->details) {
            $line .= ' ' . Util::json($this->details);
        }
        return $line;
    }
}
