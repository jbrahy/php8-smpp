# Contributing to php8-smpp

Thank you for considering contributing to php8-smpp! This document provides guidelines and instructions for contributing to the project.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [Getting Started](#getting-started)
- [Development Workflow](#development-workflow)
- [Code Standards](#code-standards)
- [Testing Requirements](#testing-requirements)
- [Submitting Changes](#submitting-changes)
- [Reporting Issues](#reporting-issues)

---

## Code of Conduct

### Our Pledge

We are committed to providing a welcoming and inclusive environment for all contributors, regardless of experience level, gender identity, sexual orientation, disability, personal appearance, race, ethnicity, age, religion, or nationality.

### Expected Behavior

- Be respectful and considerate in communication
- Welcome newcomers and help them get started
- Focus on constructive criticism
- Accept responsibility for mistakes and learn from them
- Prioritize what's best for the community

### Unacceptable Behavior

- Harassment, trolling, or discriminatory language
- Personal attacks or insults
- Publishing others' private information
- Spam or excessive self-promotion

---

## Getting Started

### Prerequisites

- PHP 8.0, 8.1, 8.2, or 8.3
- Composer
- Git
- ext-mbstring
- ext-sockets

### Setting Up Development Environment

1. **Fork the repository**

   Visit https://github.com/php8-smpp/php8-smpp and click "Fork"

2. **Clone your fork**

   ```bash
   git clone https://github.com/YOUR_USERNAME/php8-smpp.git
   cd php8-smpp
   ```

3. **Install dependencies**

   ```bash
   composer install
   ```

4. **Add upstream remote**

   ```bash
   git remote add upstream https://github.com/php8-smpp/php8-smpp.git
   ```

5. **Verify installation**

   ```bash
   # Run tests
   vendor/bin/phpunit

   # Run static analysis
   vendor/bin/phpstan analyze
   ```

---

## Development Workflow

### Branching Strategy

We use a simplified Git workflow:

- `main` - Stable, production-ready code
- `develop` - Integration branch for new features
- Feature branches - For individual features or bug fixes

### Creating a Feature Branch

```bash
# Update your local repository
git checkout main
git pull upstream main

# Create a feature branch
git checkout -b feature/your-feature-name
```

**Branch Naming Conventions:**
- `feature/feature-name` - New features
- `fix/bug-description` - Bug fixes
- `docs/documentation-topic` - Documentation updates
- `test/test-description` - Test additions/improvements
- `refactor/refactor-description` - Code refactoring

### Making Changes

1. **Make your changes** following the [Code Standards](#code-standards)

2. **Write or update tests** for your changes

3. **Run tests** to ensure everything passes:
   ```bash
   vendor/bin/phpunit
   ```

4. **Run static analysis**:
   ```bash
   vendor/bin/phpstan analyze
   ```

5. **Commit your changes** with descriptive messages:
   ```bash
   git add .
   git commit -m "feat: add support for X feature"
   ```

### Commit Message Format

We follow [Conventional Commits](https://www.conventionalcommits.org/):

```
<type>(<scope>): <subject>

<body>

<footer>
```

**Types:**
- `feat:` - New feature
- `fix:` - Bug fix
- `docs:` - Documentation changes
- `test:` - Test additions or modifications
- `refactor:` - Code refactoring
- `perf:` - Performance improvements
- `style:` - Code style changes (formatting, no logic change)
- `chore:` - Maintenance tasks

**Examples:**
```
feat(client): add support for SCTP transport

Add SCTPTransport class implementing TransportInterface for
SCTP protocol support as alternative to TCP sockets.

Closes #42
```

```
fix(parser): handle truncated PDU headers correctly

Add validation to ensure PDU header is at least 16 bytes
before attempting to parse. Prevents potential crashes on
malformed data from untrusted SMSC sources.
```

---

## Code Standards

### PHP Standards

We follow modern PHP best practices:

#### 1. Type Declarations

Always use strict typing and type declarations:

```php
<?php

declare(strict_types=1);

namespace Smpp\Example;

class Example
{
    public function method(string $param): int
    {
        // ...
    }
}
```

#### 2. Constructor Property Promotion

Use PHP 8.0+ constructor property promotion:

```php
// Good
public function __construct(
    private string $systemId,
    private string $password
) {
}

// Avoid (legacy style)
private string $systemId;

public function __construct(string $systemId)
{
    $this->systemId = $systemId;
}
```

#### 3. Naming Conventions

- **Classes:** PascalCase (e.g., `PDUParser`, `SocketTransport`)
- **Methods:** camelCase (e.g., `sendSMS`, `parsePduHeader`)
- **Variables:** camelCase (e.g., `$messageId`, `$dataCoding`)
- **Constants:** UPPER_SNAKE_CASE (e.g., `DATA_CODING_UCS2`)

#### 4. No Closing PHP Tag

Never include closing `?>` tag at the end of PHP files.

#### 5. Code Formatting

- Indentation: 4 spaces (no tabs)
- Line length: 120 characters maximum (flexible)
- Braces: Opening brace on same line for methods/classes
- One blank line between methods

### Documentation Standards

#### PHPDoc Comments

Document all public methods and complex private methods:

```php
/**
 * Sends an SMS message through the SMSC.
 *
 * Automatically handles concatenated SMS for messages exceeding
 * single SMS length limits (160 chars for GSM, 70 for UCS-2).
 *
 * @param Address $from Source address (sender)
 * @param Address $to Destination address (recipient)
 * @param string $message Message text
 * @param Tag[]|null $tags Optional TLV parameters
 * @param int $dataCoding Data coding scheme (default: GSM 03.38)
 *
 * @return string|false Message ID on success, false on failure
 *
 * @throws SmppException On protocol errors
 * @throws SocketTransportException On network errors
 */
public function sendSMS(
    Address $from,
    Address $to,
    string $message,
    ?array $tags = null,
    int $dataCoding = Smpp::DATA_CODING_DEFAULT
): string|false {
    // ...
}
```

### Security Standards

#### Input Validation

Always validate input at API boundaries:

```php
// Good
public function __construct(string $value, int $numberType)
{
    if ($numberType === Smpp::TON_ALPHANUMERIC && strlen($value) > 11) {
        throw new SmppInvalidArgumentException('Alphanumeric address may only contain 11 chars');
    }
    $this->value = $value;
}
```

#### Binary Data Handling

Handle binary data carefully to prevent parsing vulnerabilities:

```php
// Good - Check length before unpacking
if (strlen($data) < PDUHeader::PDU_HEADER_LENGTH) {
    throw new PDUParseException("PDU header must be at least 16 bytes");
}

$header = unpack('Nlength/Nid/Nstatus/Nsequence', $data);
```

#### Credential Handling

Never log or expose credentials:

```php
// Good
$this->logger->debug('Binding with system_id: ' . $systemId);

// Bad - Never do this
$this->logger->debug('Binding with password: ' . $password);
```

---

## Testing Requirements

### Test Coverage Goals

- All new features must include tests
- Bug fixes should include regression tests
- Aim for 70%+ code coverage
- 100% coverage for security-critical code (PDU parsing, validation)

### Writing Tests

We use PHPUnit 9.6+. Tests should be:

- **Isolated** - No dependencies on external services
- **Fast** - Use mocks for I/O operations
- **Deterministic** - Same input always produces same output
- **Readable** - Clear test names and assertions

#### Test Structure

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Protocol;

use PHPUnit\Framework\TestCase;
use Smpp\Protocol\PDUParser;

class PDUParserTest extends TestCase
{
    private PDUParser $parser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new PDUParser(new NullLogger());
    }

    /**
     * Test: parsePduHeader() correctly parses valid header
     */
    public function testParsePduHeaderParsesValidHeader(): void
    {
        $headerData = pack('NNNN', 16, 0x80000001, 0, 1);

        $header = $this->parser->parsePduHeader($headerData);

        $this->assertEquals(16, $header->getCommandLength());
        $this->assertEquals(0x80000001, $header->getCommandId());
    }

    /**
     * Test: parsePduHeader() throws exception on truncated header
     */
    public function testParsePduHeaderThrowsExceptionOnTruncatedHeader(): void
    {
        $this->expectException(PDUParseException::class);
        $this->expectExceptionMessage('PDU header must be at least 16 bytes');

        $this->parser->parsePduHeader('short');
    }
}
```

#### Test Naming

- Test methods: `testMethodNameDoesExpectedBehavior()`
- Use descriptive names: `testParsePduHeaderThrowsExceptionOnTruncatedHeader`
- Start with verb: test, throws, returns, handles

#### Using Mocks

Mock external dependencies (transport, logger):

```php
public function testBindTransmitterOpensConnection(): void
{
    $transport = $this->createMock(TransportInterface::class);
    $transport->expects($this->once())
        ->method('open');

    $client = new Client($transport, 'user', 'pass');
    $client->bindTransmitter();
}
```

### Running Tests

```bash
# Run all tests
vendor/bin/phpunit

# Run specific test file
vendor/bin/phpunit tests/Unit/ClientTest.php

# Run with coverage
vendor/bin/phpunit --coverage-html coverage/

# Run specific test method
vendor/bin/phpunit --filter testBindTransmitter
```

### Static Analysis

Ensure PHPStan passes at maximum level:

```bash
vendor/bin/phpstan analyze
```

Fix any errors before submitting PR.

---

## Submitting Changes

### Pull Request Process

1. **Update your branch** with latest upstream changes:
   ```bash
   git checkout main
   git pull upstream main
   git checkout feature/your-feature
   git rebase main
   ```

2. **Push to your fork**:
   ```bash
   git push origin feature/your-feature
   ```

3. **Create Pull Request** on GitHub:
   - Visit your fork on GitHub
   - Click "New Pull Request"
   - Select `develop` as base branch (or `main` for hotfixes)
   - Select your feature branch as compare branch
   - Fill in the PR template

### Pull Request Requirements

Your PR must:

- ✅ Include tests for new functionality
- ✅ Pass all existing tests (`vendor/bin/phpunit`)
- ✅ Pass static analysis (`vendor/bin/phpstan analyze`)
- ✅ Include documentation updates if API changes
- ✅ Have descriptive commit messages
- ✅ Be rebased on latest `develop` branch
- ✅ Have a clear description of what and why

### Pull Request Template

```markdown
## Description

Brief description of changes and motivation.

## Type of Change

- [ ] Bug fix (non-breaking change fixing an issue)
- [ ] New feature (non-breaking change adding functionality)
- [ ] Breaking change (fix or feature causing existing functionality to change)
- [ ] Documentation update

## Testing

Describe testing performed:
- [ ] Unit tests added/updated
- [ ] Integration tests added/updated
- [ ] Manual testing performed

## Checklist

- [ ] Code follows project style guidelines
- [ ] Self-review of code performed
- [ ] Comments added for complex logic
- [ ] Documentation updated
- [ ] Tests pass locally
- [ ] PHPStan passes
- [ ] No new warnings introduced

## Related Issues

Closes #123
```

### Review Process

1. Maintainers will review your PR within 3-5 business days
2. Address any requested changes by pushing new commits
3. Once approved, a maintainer will merge your PR
4. Your contribution will be acknowledged in release notes

---

## Reporting Issues

### Before Submitting an Issue

- Search existing issues to avoid duplicates
- Update to the latest version and check if issue persists
- Gather information about your environment

### Bug Reports

Include the following information:

```markdown
**Environment:**
- PHP Version: 8.1.15
- Library Version: 0.1.0
- OS: Ubuntu 22.04

**Description:**
Clear description of the bug.

**Steps to Reproduce:**
1. Create client with...
2. Call method...
3. Observe error...

**Expected Behavior:**
What should happen.

**Actual Behavior:**
What actually happens.

**Error Messages/Stack Trace:**
```
Paste error messages here
```

**Minimal Reproducible Example:**
```php
<?php
// Minimal code that reproduces the issue
```
```

### Feature Requests

For feature requests, describe:

- **Use case:** What problem does this solve?
- **Proposed solution:** How should it work?
- **Alternatives considered:** Other approaches you've thought of
- **SMPP specification reference:** If applicable, reference SMPP v3.4 spec

---

## Additional Guidelines

### Performance Considerations

- Avoid N+1 queries or loops in hot paths
- Cache expensive computations
- Profile code for high-throughput scenarios
- Document performance characteristics in comments

### Backward Compatibility

This project follows semantic versioning:

- **Major version (1.0.0):** Breaking changes allowed
- **Minor version (0.1.0):** New features, backward compatible
- **Patch version (0.0.1):** Bug fixes, backward compatible

Avoid breaking changes in minor/patch releases.

### Documentation Updates

When adding features, update:

- `docs/api-reference.md` - API documentation
- `README.md` - If user-facing feature
- Inline code comments
- Example files in `docs/examples/`

---

## Questions?

- Open an issue with the `question` label
- Check existing documentation in `/docs`
- Review SMPP v3.4 specification: https://smpp.org/SMPP_v3_4_Issue1_2.pdf

---

## Recognition

Contributors will be:

- Listed in release notes
- Acknowledged in commit messages (Co-Authored-By)
- Added to CONTRIBUTORS file (if substantial contribution)

Thank you for contributing to php8-smpp! 🎉
