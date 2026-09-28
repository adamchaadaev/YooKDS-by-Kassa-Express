<?php
namespace YooKDS;

/** A safe, user-facing error. Never expose database errors or order PII. */
final class Problem extends \RuntimeException {
    public string $slug;
    public int $status;

    public function __construct(string $slug, string $message, int $status = 409) {
        parent::__construct($message);
        $this->slug = $slug;
        $this->status = $status;
    }
}
