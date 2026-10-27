<?php

namespace App\Contracts;

interface DnsResolver
{
    /** @return string[] values for the record type (A: IPs, CNAME: targets, TXT: strings). */
    public function lookup(string $name, string $type): array;
}
