<?php

namespace McCrash\Services;

use McCrash\Support\Analyzer;
use McCrash\Support\LogScanner;
use McCrash\Support\RuleEngine;

/** The known-pattern rules shipped in resources/rules.json, and what is built on them. */
class Engine
{
    private ?Analyzer $analyzer = null;

    private ?LogScanner $scanner = null;

    public function rules(): RuleEngine
    {
        return RuleEngine::fromFile(dirname(__DIR__, 2).'/resources/rules.json');
    }

    public function analyzer(): Analyzer
    {
        return $this->analyzer ??= new Analyzer($this->rules());
    }

    public function scanner(): LogScanner
    {
        return $this->scanner ??= new LogScanner($this->analyzer(), $this->rules());
    }

    /** Part of every cache key: a new rules file refreshes old verdicts. */
    public function version(): string
    {
        return $this->rules()->version();
    }
}
