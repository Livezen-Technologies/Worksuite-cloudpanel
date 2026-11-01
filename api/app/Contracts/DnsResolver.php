<?php

namespace App\Contracts;

interface DnsResolver
{
    /** @return string[] values for the record type (A: IPs, CNAME: targets, TXT: strings, CAA: '0 issue "ca.example"'). */
    public function lookup(string $name, string $type): array;
}
