// YARA rules for the antivirus scan (AV-1), loaded by ClamAV from its database directory.
// ClamAV supports a subset of YARA: text and hex strings with simple conditions, no modules.
// Detections appear as YARA.<rule name>.UNOFFICIAL in the panel's threat list.

rule dmp_php_request_exec
{
    meta:
        description = "PHP that runs code or commands taken straight from a request"
    strings:
        $open = "<?php" nocase
        $a = /(eval|assert|system|exec|shell_exec|passthru|popen|proc_open)\s*\(\s*\$_(GET|POST|REQUEST|COOKIE|SERVER)/ nocase
    condition:
        $open and $a
}

rule dmp_php_encoded_eval
{
    meta:
        description = "PHP that evaluates base64, gzinflate or rot13 encoded code"
    strings:
        $open = "<?php" nocase
        $a = /eval\s*\(\s*(base64_decode|gzinflate|gzuncompress|gzdecode|str_rot13|strrev)\s*\(/ nocase
    condition:
        $open and $a
}

rule dmp_php_known_shell
{
    meta:
        description = "Names of widely used PHP web shells"
    strings:
        $a = "FilesMan" ascii
        $b = "c99shell" nocase
        $c = "r57shell" nocase
        $d = "b374k" nocase
        $e = "IndoXploit" nocase
        $f = /WSO\s+[0-9]\.[0-9]/
    condition:
        any of them
}

rule dmp_php_mailer
{
    meta:
        description = "Bulk mailer scripts dropped on compromised sites"
    strings:
        $open = "<?php" nocase
        $a = "Leaf PHPMailer" nocase
        $b = /\$_(POST|GET)\s*\[\s*['"]?(emaillist|mailist|maillist)['"]?\s*\]/ nocase
    condition:
        $open and any of ($a, $b)
}
