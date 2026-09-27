<?php

declare(strict_types=1);

return [
    'common.back_home' => 'Back to home page',
    'bad_request.title' => 'Bad request',
    'bad_request.message' => 'The request could not be processed.',
    'not_found.title' => 'Page not found',
    'not_found.message' => 'The requested page does not exist or has been moved.',
    'method_not_allowed.title' => 'Method not allowed',
    'method_not_allowed.message' => 'This action is not available at this address.',
    'csrf.title' => 'Session expired',
    'csrf.message' => 'Your session has expired or the form is no longer valid. Reload the page and try again.',
    'too_many_requests.title' => 'Too many requests',
    'too_many_requests.message' => 'You have made too many requests. Please wait before trying again.',
    'server.title' => 'Internal error',
    'server.message' => 'An unexpected error occurred. Our team has been notified; please try again later.',

    'api.bad_request' => 'Invalid request.',
    'api.invalid_json' => 'The request body must be a valid JSON object.',
    'api.unauthorized' => 'Missing, invalid or revoked API key. Use the “Authorization: Bearer sk_…” header.',
    'api.insufficient_credits' => 'Insufficient credits for a new verification. Top up your account.',
    'api.not_found' => 'Resource not found.',
    'api.method_not_allowed' => 'HTTP method not allowed for this resource.',
    'api.payload_too_large' => 'The request body is too large.',
    'api.unsupported_media_type' => 'Unsupported content type: use “Content-Type: application/json”.',
    'api.validation_failed' => 'Some fields are invalid (see “details”).',
    'api.rate_limited' => 'Too many requests. Retry after the delay given by the Retry-After header.',
    'api.email_locked' => 'Too many failed verifications for this address. Try again later.',
    'api.idempotency_key_reused' => 'This idempotency key was already used for a different request. Use a new key.',
    'api.idempotency_in_progress' => 'A request with this idempotency key is still in progress. Retry in a moment.',
    'api.server_error' => 'Internal error. Please try again later.',
];
