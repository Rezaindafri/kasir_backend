<?php

return [
    // Fingerprints cannot authenticate a request. The one below belongs to a
    // token exposed in an old commit: deny it without rewriting history or
    // modifying existing user credentials/database rows.
    'compromised_token_hashes' => [
        'd4783cc966872cdd0037706ce4f5161cba241db3627ac1dc8eae9cbe640beb5a',
    ],
];
