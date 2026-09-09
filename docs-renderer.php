<?php
declare(strict_types=1);
require_once __DIR__ . '/vendor/parsedown/Parsedown.php';

final class HandbookMarkdown extends Parsedown
{
    public array $contents = [];
    private string $prefix;
    public function __construct(string $prefix = '')
    {
        $this->prefix = $prefix;
        $this->setSafeMode(true);
        $this->setMarkupEscaped(true);
    }

    protected function blockHeader($line)
    {
        $block = parent::blockHeader($line);
        if ($block !== null) {
            $heading = $block['element']['handler']['argument'];
            $id = $this->prefix . 'section-' . count($this->contents);
            $block['element']['attributes']['id'] = $id;
            $this->contents[] = ['id'=>$id,'title'=>$heading,'level'=>(int)substr($block['element']['name'],1)];
        }
        return $block;
    }
}
