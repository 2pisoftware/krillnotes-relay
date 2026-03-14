# Admin CLI: reset-password command

**Date:** 2026-03-15
**Status:** Draft

## Summary

Add a `reset-password` subcommand to `bin/admin.php` that directly sets a new password hash for a given account. This is the practical workaround for users who need a password reset while the email delivery path (`POST /auth/reset-password`) remains unimplemented.

## Usage

```
php bin/admin.php reset-password <email> <new_password>
```

## Behaviour

1. Validate that both `email` (`$argv[2]`) and `new_password` (`$argv[3]`) are provided; print usage hint and exit 1 if missing.
2. Look up the account by email via a direct PDO query on the `accounts` table; exit 1 with a clear error if not found.
3. Hash the new password using the same pattern as `AuthService::hashPassword()`:
   ```php
   $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
   $hash = password_hash($newPassword, $algo);
   ```
   No minimum password length is enforced — the admin is trusted, and the existing API (`RegisterHandler`) has no minimum either.
4. `UPDATE accounts SET password_hash = ? WHERE account_id = ?`.
5. Print: `Password updated for <email>`

## Out of scope

- Session invalidation (existing sessions are left untouched)
- Interactive/hidden password prompt
- Token-based reset flow

## Implementation location

Single new function `resetPassword(\PDO $pdo, string $email, string $newPassword): void` added to `bin/admin.php`, following existing patterns. Wired into the `switch` dispatch block as `case 'reset-password'`. Help text and `CLAUDE.md` updated to document the new command.

## Files changed

| File | Change |
|------|--------|
| `bin/admin.php` | Add `resetPassword()` function + dispatch case |
| `bin/admin.php` (help) | Add `reset-password` to usage output |
| `CLAUDE.md` | Add `reset-password` to Key commands; fix migration count (001–007 → 001–008) |
| `tests/Unit/Cli/AdminResetPasswordTest.php` | New test class following existing `AdminAccountsTest` pattern |
