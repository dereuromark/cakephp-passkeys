## Summary

<!-- 1-3 bullets describing what changes and why. -->

## Checklist

- [ ] `composer test` passes (PHPUnit)
- [ ] `composer stan` passes (PHPStan level 8)
- [ ] `composer cs-check` passes (PhpCollective standard)
- [ ] `npm test` passes (Vitest)
- [ ] `npm run typecheck` passes
- [ ] `npm run build` succeeds (ESM + IIFE)
- [ ] CHANGELOG.md updated under `[Unreleased]` if user-facing
- [ ] No `@<word>` tokens in commit messages, PR title, or this body
      (GitHub auto-mentions strangers; only inside fenced code blocks is safe)

## Security impact

<!-- Mark one. Anything touching ceremony / session / CSRF / rate-limit
     belongs here even if the diff looks small. -->

- [ ] No security impact
- [ ] Security-impacting (describe below)
