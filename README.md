# Clinical Co-Pilot — an AI agent for OpenEMR

> A fork of [OpenEMR](https://open-emr.org) that embeds a **Clinical Co-Pilot**: an AI agent giving a physician cited, verified pre-visit context on the patient in front of them — what changed since the last visit, whether control is on target, what monitoring is overdue, what orders are in flight — in a conversation-style panel inside the chart. Built for the Gauntlet AI AgentForge case study.

**🔗 Live deployment:** _Not yet deployed — **TODO:** add the public URL here. (Required hard gate on every submission.)_

## Project documentation

| Document | What it is |
|---|---|
| [USERS.md](USERS.md) | The target user (an outpatient endocrinologist), their workflow, and the use cases the agent addresses — the source of truth every capability traces back to. |
| [ARCHITECTURE.md](ARCHITECTURE.md) | The plan for building the agent: placement, tools, verification, observability, authorization, failure model, and tradeoffs. Opens with a one-page summary. |
| [AUDIT.md](AUDIT.md) | Pre-build, code-level audit of this fork — security, performance, architecture, data quality, and compliance — the hard gate that precedes building the AI layer. |
| [ARCHITECTURE_COMPLETE.md](ARCHITECTURE_COMPLETE.md) | The full build spec: fact layer, lab contracts, digest model, and build units. |
| [docs/clinical-copilot-tradeoffs.md](docs/clinical-copilot-tradeoffs.md) | Decision record (T1–T21) and rejected alternatives. |
| [ONBOARDING.md](ONBOARDING.md) · [INDEX.md](INDEX.md) | Orientation to the OpenEMR codebase the agent integrates into. |

## Architecture at a glance

The co-pilot is designed as one **additive, in-process PHP module** (`interface/modules/custom_modules/oe-module-clinical-copilot/`) that inherits OpenEMR's session, ACL, CSRF, and audit logging rather than bolting on a separate service. Its shape is a direct response to the audit findings:

- **The LLM narrates and navigates; it never extracts.** Five deterministic capabilities read the chart through OpenEMR's own service layer and produce typed facts with citations; the model only writes prose over those facts and requests which capability to run next.
- **Verification is a deterministic gate.** Every LLM output is checked before it is rendered — every claim must cite a real fact, every number must be grounded in a cited fact, and every fact's patient ID must match the pinned patient. Failure is closed; unverified prose is never shown.
- **Patient pinning is structural.** A session is bound server-side to one patient, and no tool accepts a patient argument — so the agent cannot reach another patient's data.
- **PHI stays inside the boundary.** Synthetic patients only in this phase; the LLM provider is the single named trust boundary; observability traces live in the EMR's own database, not a third-party SaaS.

See [ARCHITECTURE.md](ARCHITECTURE.md) for the full design and [AUDIT.md](AUDIT.md) for the findings that drove it.

## Run it locally

Docker-first — no host PHP or Node needed:

```bash
cd docker/development-easy && docker compose up --detach --wait
# app:        http://localhost:8300   (login: admin / pass)
# phpMyAdmin: http://localhost:8310
```

Load the demo patient data set during setup so there is a chart to review. Full setup, testing, and code-quality workflows are documented in [CONTRIBUTING.md](CONTRIBUTING.md) and [CLAUDE.md](CLAUDE.md); [ONBOARDING.md](ONBOARDING.md) is a guided tour of how the codebase is organized.

---

_The original OpenEMR project README follows._

[![Syntax Status](https://github.com/openemr/openemr/actions/workflows/syntax.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/syntax.yml)
[![Styling Status](https://github.com/openemr/openemr/actions/workflows/styling.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/styling.yml)
[![Testing Status](https://github.com/openemr/openemr/actions/workflows/test.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/test.yml)
[![JS Unit Testing Status](https://github.com/openemr/openemr/actions/workflows/js-test.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/js-test.yml)
[![PHPStan](https://github.com/openemr/openemr/actions/workflows/phpstan.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/phpstan.yml)
[![Rector](https://github.com/openemr/openemr/actions/workflows/rector.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/rector.yml)
[![ShellCheck](https://github.com/openemr/openemr/actions/workflows/shellcheck.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/shellcheck.yml)
[![Docker Compose Linting](https://github.com/openemr/openemr/actions/workflows/docker-compose-lint.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/docker-compose-lint.yml)
[![Dockerfile Linting](https://github.com/openemr/openemr/actions/workflows/docker-lint-hadolint.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/docker-lint-hadolint.yml)
[![Isolated Tests](https://github.com/openemr/openemr/actions/workflows/isolated-tests.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/isolated-tests.yml)
[![Inferno Certification Test](https://github.com/openemr/openemr/actions/workflows/inferno-test.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/inferno-test.yml)
[![Composer Checks](https://github.com/openemr/openemr/actions/workflows/composer.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/composer.yml)
[![Composer Require Checker](https://github.com/openemr/openemr/actions/workflows/composer-require-checker.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/composer-require-checker.yml)
[![API Docs Freshness Checks](https://github.com/openemr/openemr/actions/workflows/api-docs.yml/badge.svg)](https://github.com/openemr/openemr/actions/workflows/api-docs.yml)
[![codecov](https://codecov.io/gh/openemr/openemr/graph/badge.svg?token=7Eu3U1Ozdq)](https://codecov.io/gh/openemr/openemr)

[![Backers on Open Collective](https://opencollective.com/openemr/backers/badge.svg)](#backers) [![Sponsors on Open Collective](https://opencollective.com/openemr/sponsors/badge.svg)](#sponsors)

# OpenEMR

[OpenEMR](https://open-emr.org) is a Free and Open Source electronic health records and medical practice management application. It features fully integrated electronic health records, practice management, scheduling, electronic billing, internationalization, free support, a vibrant community, and a whole lot more. It runs on Windows, Linux, Mac OS X, and many other platforms.

### Contributing

OpenEMR is a leader in healthcare open source software and comprises a large and diverse community of software developers, medical providers and educators with a very healthy mix of both volunteers and professionals. [Join us and learn how to start contributing today!](https://open-emr.org/wiki/index.php/FAQ#How_do_I_begin_to_volunteer_for_the_OpenEMR_project.3F)

> Already comfortable with git? Check out [CONTRIBUTING.md](CONTRIBUTING.md) for quick setup instructions and requirements for contributing to OpenEMR by resolving a bug or adding an awesome feature 😊.

### Support

Community and Professional support can be found [here](https://open-emr.org/wiki/index.php/OpenEMR_Support_Guide).

Extensive documentation and forums can be found on the [OpenEMR website](https://open-emr.org) that can help you to become more familiar about the project 📖.

### Reporting Issues and Bugs

Report these on the [Issue Tracker](https://github.com/openemr/openemr/issues). If you are unsure if it is an issue/bug, then always feel free to use the [Forum](https://community.open-emr.org/) and [Chat](https://www.open-emr.org/chat/) to discuss about the issue 🪲.

### Reporting Security Vulnerabilities

Check out [SECURITY.md](.github/SECURITY.md)

### API

Check out [API_README.md](API_README.md)

### Docker

Check out [DOCKER_README.md](DOCKER_README.md)

### FHIR

Check out [FHIR_README.md](FHIR_README.md)

### For Developers

If using OpenEMR directly from the code repository, then the following commands will build OpenEMR (Node.js version 24.* is required) :

```shell
composer install --no-dev
npm install
npm run build
composer dump-autoload -o
```

### Contributors

This project exists thanks to all the people who have contributed. [[Contribute]](CONTRIBUTING.md).
<a href="https://github.com/openemr/openemr/graphs/contributors"><img src="https://opencollective.com/openemr/contributors.svg?width=890" /></a>


### Sponsors

Thanks to our [ONC Certification Major Sponsors](https://www.open-emr.org/wiki/index.php/OpenEMR_Certification_Stage_III_Meaningful_Use#Major_sponsors)!


### License

[GNU GPL](LICENSE)
