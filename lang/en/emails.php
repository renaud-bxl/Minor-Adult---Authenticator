<?php

declare(strict_types=1);

return [
    'common.greeting' => 'Hello,',
    'common.footer' => 'This email was sent automatically by {app}. Please do not reply.',
    'common.link_fallback' => 'If the button does not work, copy this link into your browser:',

    'verify_email.subject' => 'Confirm your email address',
    'verify_email.intro' => 'Thank you for signing up to {app}. Confirm your email address to activate your account.',
    'verify_email.action' => 'Confirm my address',
    'verify_email.expiry' => 'This link is valid for {hours} hours and can only be used once.',
    'verify_email.ignore' => 'If you did not sign up, simply ignore this email.',

    'account_exists.subject' => 'Sign-up attempt with your address',
    'account_exists.intro' => 'Someone (perhaps you) tried to create a {app} account with this email address, but an account already exists.',
    'account_exists.login_action' => 'Sign in',
    'account_exists.forgot' => 'Forgot your password?',
    'account_exists.reset_action' => 'Reset the password',
    'account_exists.ignore' => 'If you did not make this request, ignore this email: your account has not been changed.',

    'password_reset.subject' => 'Reset your password',
    'password_reset.intro' => 'We received a request to reset the password of your {app} account.',
    'password_reset.action' => 'Choose a new password',
    'password_reset.expiry' => 'This link is valid for {minutes} minutes and can only be used once.',
    'password_reset.ignore' => 'If you did not make this request, ignore this email: your password remains unchanged.',

    'password_changed.subject' => 'Your password has been changed',
    'password_changed.intro' => 'The password of your {app} account has just been changed.',
    'password_changed.sessions' => 'For your security, all open sessions have been closed.',
    'password_changed.not_you' => 'If you did not make this change, secure your account immediately:',
    'password_changed.action' => 'reset the password',

    'verification_code.subject' => 'Your verification code',
    'verification_code.intro' => '{project} is asking you to verify your age with {app}. Enter this code to confirm your email address:',
    'verification_code.expiry' => 'This code is valid for {minutes} minutes.',
    'verification_code.never_share' => 'Do not share this code with anyone: we will never ask for it by phone or message.',
    'verification_code.ignore' => 'If you did not request this, ignore this email: no verification can take place without this code.',

    'verification_link.subject' => 'Confirm your email address',
    'verification_link.intro' => '{project} is asking you to verify your age with {app}. For security, confirm your email address with this single-use link:',
    'verification_link.action' => 'Confirm my address',
    'verification_link.expiry' => 'This link is valid for {minutes} minutes and can only be used once.',
];
