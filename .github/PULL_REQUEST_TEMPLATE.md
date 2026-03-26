## Description

<!-- What does this PR do? Be specific. Reference issue numbers if applicable. -->


## Type of Change

<!-- Check the one that applies. -->

- [ ] Feature — new functionality
- [ ] Bugfix — corrects a defect
- [ ] Refactor — code change with no behavior change
- [ ] Docs — documentation only
- [ ] Chore — build, config, dependencies, CI

## Testing

<!-- Describe how this was tested. Include steps to reproduce the test scenario. -->

**Device profile tested on:**
- [ ] Standard spec (e.g. modern laptop)
- [ ] Low-spec device (e.g. Raspberry Pi 4, 2 GB RAM, 1.5 GHz)
- [ ] XAMPP offline environment (no internet)

**Test steps:**
1. 
2. 
3. 

## Screenshots / Screen Recordings

<!-- Add screenshots or recordings if the change affects UI. -->
<!-- Drag and drop images here, or remove this section if not applicable. -->

| Before | After |
|--------|-------|
| _none_ | _none_ |

## Checklist

<!-- All items must be checked before requesting review. -->

- [ ] Code follows project style (PSR-12 PHP, ESLint defaults for JS)
- [ ] PHP lint passes (`make lint`)
- [ ] PHPUnit tests pass (`make test`)
- [ ] Playwright e2e tests pass (`make test-e2e`)
- [ ] Tested on a low-spec device profile (or not applicable)
- [ ] No new external/internet dependencies introduced
- [ ] `.env.example` updated if new env vars were added
- [ ] `CONTRIBUTING.md` / docs updated if workflows changed
- [ ] Migration files added for any schema changes (`make migrate`)
- [ ] No `.log`, `.env`, or compiled `dist/` files committed

## Related Issues / PRs

<!-- Closes #___ | References #___ -->
