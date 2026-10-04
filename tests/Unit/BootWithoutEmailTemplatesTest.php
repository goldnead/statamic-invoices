<?php

use Symfony\Component\Process\Process;

/*
 * A site without email-templates. In a separate PHP process, because this
 * suite has the sibling installed as a dev dependency and would hide a line
 * that touches a missing class. The CI matrix has no leg without it, so this is
 * the proof that the built-in mail stays the mail there.
 */
it('boots and keeps the built-in mail without email-templates', function () {
    $process = new Process([PHP_BINARY, __DIR__.'/../Boot/boot-without-email-templates.php']);
    $process->setTimeout(120)->run();

    $output = $process->getOutput().$process->getErrorOutput();

    expect($process->getExitCode())->toBe(0, $output)
        ->and($output)->toContain('booted, registered no, template none, sources 0, product Chorleitungskurs')
        ->not->toContain('not found');
});
