# Contributing

1. Open an issue before large changes.
2. Fork the repository and create a branch from `main`.
3. Run the checks before opening a pull request:

```bash
composer test
composer stan
composer pint
```

To measure source line coverage, enable Xdebug (or PCOV) for your CLI PHP and run:

```bash
composer test:coverage
```

The full unit and feature suites are included. Open `build/coverage/index.html` for
uncovered lines; `build/coverage/clover.xml` contains the machine-readable report.
Coverage includes all of `src/`, including files no test executes. Test-only runtime
and filesystem overrides are reset after each test and otherwise call native PHP.

Report security issues privately. See [docs/reference/security.md](docs/reference/security.md).
