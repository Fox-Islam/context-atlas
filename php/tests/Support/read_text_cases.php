<?php

declare(strict_types=1);

/*
 * Plain text: RFC-style numbered headings and an indented body, a title heading before a
 * longer paragraph, and code indented past the body.
 */

$rfc = "\u{FEFF}Abstract\n\n   This document defines a text file that lists how to report problems, and it\n   says where the file must live.\n\n1.  Introduction\n\n   Researchers often cannot find who to tell about a problem they found on a\n   site, so this file gives them a place to look.\n\n2.5.3.  Contact\n\n   The Contact field gives a way to reach the team.\n\n      Contact: mailto:security@example.com\n      Contact: tel:+1-201-555-0123\n\nAppendix A.  Notes\n\n   Short.\n";
$flush = "Opening hours\n\nThe library opens at nine every weekday and closes at five, except on bank holidays.\n\nDear reader,\n\nThanks.\n\n    \$ make install\n    \$ make test\n";

return [
    ['name' => 'text: numbered RFC headings, a title heading, and code indented past the body', 'kind' => 'read', 'input' => ['format' => 'text', 'text' => $rfc]],
    ['name' => 'text: a short line is a heading only before a longer paragraph; indented lines are code', 'kind' => 'read', 'input' => ['format' => 'text', 'text' => $flush]],
];
