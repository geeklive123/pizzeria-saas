<?php

namespace App\Printing;

final class EscPosDocumentBuilder
{
    private string $bytes = "\x1b\x40\x1b\x74\x02";

    private string $plainText = '';

    public function alignCenter(): self
    {
        $this->bytes .= "\x1b\x61\x01";

        return $this;
    }

    public function alignLeft(): self
    {
        $this->bytes .= "\x1b\x61\x00";

        return $this;
    }

    public function bold(bool $enabled = true): self
    {
        $this->bytes .= "\x1b\x45".($enabled ? "\x01" : "\x00");

        return $this;
    }

    public function doubleSize(bool $enabled = true): self
    {
        $this->bytes .= "\x1d\x21".($enabled ? "\x11" : "\x00");

        return $this;
    }

    public function line(string $text = ''): self
    {
        $text = $this->sanitize($text);
        $encoded = iconv('UTF-8', 'CP850//TRANSLIT//IGNORE', $text);
        $this->bytes .= ($encoded === false ? $text : $encoded)."\n";
        $this->plainText .= $text."\n";

        return $this;
    }

    public function finish(): ThermalDocument
    {
        $this->bytes .= "\n\n\n\x1d\x56\x42\x00";

        return new ThermalDocument($this->bytes, rtrim($this->plainText)."\n");
    }

    private function sanitize(string $text): string
    {
        return str_replace(["\r", "\0"], ['', ''], $text);
    }
}
