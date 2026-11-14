<?php

namespace App\Tests\Simulation;

use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * HelloAsso simulé pour les tests (framework.http_client.mock_response_factory) : jetons, association,
 * intentions de paiement ; HelloAssoSimule::payer() fait comme si la famille avait payé (et les échéances suivantes).
 */
class HelloAssoSimule
{
    /** @var array<int, array{corps: array<string, mixed>, payees: int}> intentions créées, par numéro */
    public static array $intentions = [];

    /** @var list<array{methode: string, url: string}> */
    public static array $appels = [];

    public static bool $refuserIdentifiants = false;

    private static int $prochain = 5000;

    public static function remettreAZero(): void
    {
        self::$intentions = [];
        self::$appels = [];
        self::$refuserIdentifiants = false;
    }

    /** La famille a payé : la commande existe et $echeances échéances sont encaissées. */
    public static function payer(int $intention, int $echeances = 1): void
    {
        self::$intentions[$intention]['payees'] = $echeances;
    }

    /** @param array<string, mixed> $options */
    public function __invoke(string $methode, string $url, array $options = []): MockResponse
    {
        self::$appels[] = ['methode' => $methode, 'url' => $url];
        $chemin = (string) parse_url($url, \PHP_URL_PATH);

        if (str_ends_with($chemin, '/oauth2/token')) {
            return self::$refuserIdentifiants
                ? self::json(['error' => 'invalid_client'], 400)
                : self::json(['access_token' => 'jeton-' . bin2hex(random_bytes(4)), 'expires_in' => 1800, 'refresh_token' => 'rafraichir-test', 'token_type' => 'bearer']);
        }
        if ('GET' === $methode && preg_match('#^/v5/organizations/([^/]+)$#', $chemin, $m)) {
            return 'inconnue' === $m[1] ? self::json(['message' => 'not found'], 404) : self::json(['name' => 'Association ' . strtoupper($m[1]), 'organizationSlug' => $m[1]]);
        }
        if ('POST' === $methode && preg_match('#^/v5/organizations/([^/]+)/checkout-intents$#', $chemin)) {
            $corps = json_decode((string) ($options['body'] ?? '{}'), true);
            $id = ++self::$prochain;
            self::$intentions[$id] = ['corps' => $corps, 'payees' => 0];

            return self::json(['id' => $id, 'redirectUrl' => 'https://www.helloasso-sandbox.com/paiement-simule/' . $id]);
        }
        if ('GET' === $methode && preg_match('#^/v5/organizations/([^/]+)/checkout-intents/(\d+)$#', $chemin, $m)) {
            $intention = self::$intentions[(int) $m[2]] ?? null;
            if (!$intention) {
                return self::json(['message' => 'not found'], 404);
            }
            $reponse = ['id' => (int) $m[2], 'redirectUrl' => 'https://www.helloasso-sandbox.com/paiement-simule/' . $m[2], 'metadata' => $intention['corps']['metadata'] ?? []];
            if ($intention['payees'] > 0) {
                $montants = [$intention['corps']['initialAmount'], ...array_column($intention['corps']['terms'] ?? [], 'amount')];
                $paiements = [];
                foreach ($montants as $i => $montant) {
                    $paiements[] = ['id' => (int) $m[2] * 10 + $i, 'amount' => $montant, 'state' => $i < $intention['payees'] ? 'Authorized' : 'Pending',
                        'date' => (new \DateTimeImmutable("today +$i month"))->format(\DATE_ATOM)];
                }
                $reponse['order'] = ['id' => (int) $m[2] + 900000, 'payments' => $paiements];
            }

            return self::json($reponse);
        }

        return self::json(['message' => 'route simulée inconnue : ' . $methode . ' ' . $chemin], 500);
    }

    /** @param array<string, mixed> $donnees */
    private static function json(array $donnees, int $code = 200): MockResponse
    {
        return new MockResponse(json_encode($donnees), ['http_code' => $code, 'response_headers' => ['content-type' => 'application/json']]);
    }
}
