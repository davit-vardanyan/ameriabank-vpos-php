<?php

declare(strict_types=1);

namespace DavitVardanyan\AmeriabankVpos\Config;

use function assert;

use DavitVardanyan\AmeriabankVpos\Exception\ConfigurationException;

use function is_string;

use SensitiveParameter;
use SensitiveParameterValue;

/**
 * The merchant's ClientID, Username and Password.
 *
 * This is the only class in the package that holds a secret, so CONVENTIONS.md
 * §6 is either true here or decorative everywhere.
 *
 * There is no password accessor and there must never be one. The gateway only
 * ever wants the value inside one of two request-field arrays — merchantFields()
 * for InitPayment, GetBindings, MakeBindingPayment and the binding operations,
 * userFields() for GetPaymentDetails, ConfirmPayment, CancelPayment and
 * RefundPayment, whose request models carry no ClientID — so those two methods
 * are the only exits. Anything that wants the password on its own is doing
 * something the transport does not need to do. The keys of both arrays are wire
 * spellings and are load-bearing (CONVENTIONS.md §4.8).
 *
 * The credentials are injected by the transport and never appear in a request
 * DTO the caller constructs (CONVENTIONS.md §5).
 *
 * Only the password is wrapped, and the asymmetry is deliberate rather than an
 * oversight (CONVENTIONS.md §6). It is the secret; ClientID and Username are
 * identifiers. Both identifiers cross the wire in cleartext, in the request
 * body, on every call that carries them — api-surface.json declares Username
 * and Password on all twelve of its request models and ClientID on eight of
 * them, and none of the three appears in any response model — and neither
 * authenticates anything on its own, so wrapping them would advertise a
 * protection the protocol does not provide and cannot. That is a statement
 * about what this package sends, not about how the gateway holds anything at
 * its end. __debugInfo() and __serialize() draw the same line: they redact the
 * password and return both identifiers in full. The log redactor deliberately
 * does not — it replaces all three, because a log is a durable artifact read by
 * parties the merchant did not choose.
 *
 * Leak channels. Each was executed against an instance holding a distinctive
 * password on PHP 8.3.28, and each line below records what that run printed,
 * not what the language is expected to do:
 *
 * - var_dump() — closed. Prints the redaction marker, via __debugInfo().
 * - print_r() — closed, also via __debugInfo(). PHP consults the same debug
 *   hook here as var_dump() does: a control object without __debugInfo()
 *   printed the password in full, and adding the hook replaced it with the
 *   marker.
 * - serialize() — closed. The output carries the marker, via __serialize().
 * - var_export() — no interception hook exists, and against a bare string
 *   property it printed the password verbatim inside a __set_state() call.
 *   Holding the password in a SensitiveParameterValue closes it: the export
 *   renders as \SensitiveParameterValue::__set_state(array()) with nothing
 *   inside. That wrapper is why the property is not a plain string.
 * - json_encode() — printed {}. The properties are private and the class does
 *   not implement JsonSerializable, so there is nothing for it to encode.
 * - (string) — throws Error, "could not be converted to string". The class
 *   declares no __toString(), so the channel does not exist rather than being
 *   closed.
 * - Throwable::getTraceAsString(), thrown from inside the constructor — the
 *   constructor frame renders its third argument as
 *   Object(SensitiveParameterValue), which is what #[SensitiveParameter] does
 *   to a trace. The exception's own __toString() was checked as well and
 *   renders the same frame.
 *
 * Nothing above is claimed for a channel that was not run. unserialize() is not
 * a leak channel but a correctness one — see __unserialize().
 *
 * The redactor that scrubs PSR-3 log records is a separate control and belongs
 * to the transport; this class is not it.
 */
final readonly class Credentials
{
    /**
     * What the redacting hooks put where the password would be.
     *
     * A fixed string carrying no information about the secret. Not its length,
     * not a hash, not a prefix — a marker that varies with the password is a
     * disclosure of the password, in small pieces.
     */
    private const string REDACTED = '[redacted]';

    /**
     * The password, wrapped rather than bare.
     *
     * The wrapper is not decoration: it is the only thing observed to close the
     * var_export() channel, which has no hook this class could implement. See
     * the class docblock.
     */
    private SensitiveParameterValue $password;

    private string $clientId;

    private string $username;

    /**
     * Every field is validated before any of them is assigned.
     *
     * The properties are declared rather than promoted for exactly that
     * reason: promotion assigns on entry, before a constructor body could
     * reject anything, and an object that has held a rejected credential —
     * even briefly, even unreachably — is a weaker statement than one that
     * never did.
     *
     * Whitespace counts as blank. A password of spaces is a configuration
     * mistake, not a password, and the gateway would answer ResponseCode 20 to
     * it with no hint as to which of the three fields was wrong.
     *
     * Only the field name reaches the exception, never the value
     * (CONVENTIONS.md §6).
     *
     * @throws ConfigurationException
     */
    public function __construct(
        string $clientId,
        string $username,
        #[SensitiveParameter]
        string $password,
    ) {
        if (trim($clientId) === '') {
            throw ConfigurationException::blankCredential('ClientID');
        }

        if (trim($username) === '') {
            throw ConfigurationException::blankCredential('Username');
        }

        if (trim($password) === '') {
            throw ConfigurationException::blankCredential('Password');
        }

        $this->clientId = $clientId;
        $this->username = $username;
        $this->password = new SensitiveParameterValue($password);
    }

    public function clientId(): string
    {
        return $this->clientId;
    }

    public function username(): string
    {
        return $this->username;
    }

    /**
     * The credential fields of a request that identifies the merchant.
     *
     * @return array{ClientID: string, Username: string, Password: string}
     */
    public function merchantFields(): array
    {
        return [
            'ClientID' => $this->clientId,
            'Username' => $this->username,
            'Password' => $this->unwrappedPassword(),
        ];
    }

    /**
     * The credential fields of a request that carries no ClientID.
     *
     * @return array{Username: string, Password: string}
     */
    public function userFields(): array
    {
        return [
            'Username' => $this->username,
            'Password' => $this->unwrappedPassword(),
        ];
    }

    /**
     * @return array{clientId: string, username: string, password: string}
     */
    public function __debugInfo(): array
    {
        return [
            'clientId' => $this->clientId,
            'username' => $this->username,
            'password' => self::REDACTED,
        ];
    }

    /**
     * The same redacted shape __debugInfo() returns.
     *
     * A serialized Credentials is a password at rest in a session store, a
     * cache file or a queue payload, and this package has no business putting
     * one there.
     *
     * @return array{clientId: string, username: string, password: string}
     */
    public function __serialize(): array
    {
        return [
            'clientId' => $this->clientId,
            'username' => $this->username,
            'password' => self::REDACTED,
        ];
    }

    /**
     * Refuses to restore, loudly.
     *
     * __serialize() redacts, so a restored object would hold the marker string
     * where the password belongs and would fail authentication with
     * ResponseCode 20 — indistinguishable, from the caller's side, from a
     * merchant who typed the wrong password. Throwing at the point of
     * restoration names the real fault instead of deferring it to a support
     * ticket about credentials that are in fact correct.
     *
     * $data is deliberately unread. There is nothing in it worth restoring.
     *
     * @param array<array-key, mixed> $data
     *
     * @throws ConfigurationException
     */
    public function __unserialize(array $data): never
    {
        throw ConfigurationException::credentialsNotUnserializable();
    }

    /**
     * The password, unwrapped for the two field arrays and nothing else.
     *
     * Private, and named so that a grep for a password accessor finds nothing.
     *
     * SensitiveParameterValue::getValue() is declared mixed because it may hold
     * anything. The constructor is the only writer and writes a string, so the
     * narrowing below states an invariant this class already holds rather than
     * validating an input; the string return type would raise a TypeError under
     * strict_types even with assertions compiled out.
     */
    private function unwrappedPassword(): string
    {
        $password = $this->password->getValue();
        assert(is_string($password));

        return $password;
    }
}
