#!/usr/bin/env node

import { Application } from '../src/console/application.js';

// A reader that goes away (`context-atlas chunk doc.md | head -2`)
// arrives as an EPIPE error event on stdout, which Node's default handler
// rethrows as a stack trace over output the reader already has.
process.stdout.on('error', (error: NodeJS.ErrnoException) => {
    if (error.code !== 'EPIPE') {
        throw error;
    }
});

process.exitCode = await new Application().run(process.argv.slice(2));
