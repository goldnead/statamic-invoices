<?php

use Symfony\Component\Process\Process;

/*
 * A site without the webhook manager. In a separate PHP process, because this
 * suite has the manager installed as a dev dependency and would hide a
 * provider that touches a missing class (statamic-courses 0.2.0 crashed every
 * such site at boot that way).
 */
it('boots and stays out of the way without the webhook manager', function () {
    $process = new Process([PHP_BINARY, __DIR__.'/../Boot/boot-without-webhook-manager.php']);
    $process->setTimeout(120)->run();

    $output = $process->getOutput().$process->getErrorOutput();

    expect($process->getExitCode())->toBe(0, $output)
        ->and($output)->toContain('booted, bridge off, available no')
        ->not->toContain('not found');
});
