<?php

namespace App\Core\Contracts;

interface AuthInterface
{
    /** @return array{id:int,username:string,role:string}|null */
    public function user(): ?array;
    public function check(): bool;
    public function id(): ?int;
}
