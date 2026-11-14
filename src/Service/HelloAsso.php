<?php

namespace App\Service;

use App\Entity\ConnexionHelloAsso;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Appels à l'API HelloAsso (v5) pour le compte d'un club : jetons OAuth2 (client_credentials puis refresh_token,
 * gardés dans ConnexionHelloAsso), vérification du compte, création et lecture d'un paiement (Checkout).
 * Documentation : https://dev.helloasso.com/docs/api-overview
 */
class HelloAsso
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly Coffre $coffre,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public static function adresse(ConnexionHelloAsso $c): string
    {
        return $c->isTest() ? 'https://api.helloasso-sandbox.com' : 'https://api.helloasso.com';
    }

    /**
     * Vérifie les identifiants : un jeton s'obtient et l'association est trouvée.
     *
     * @return string nom de l'association chez HelloAsso
     *
     * @throws ErreurHelloAsso message lisible par le club
     */
    public function verifier(ConnexionHelloAsso $c): string
    {
        $c->oublierVerification();
        $organisation = $this->appeler($c, 'GET', '/v5/organizations/' . rawurlencode((string) $c->getSlug()));
        $nom = (string) ($organisation['name'] ?? $c->getSlug());
        $c->marquerVerifiee($nom);
        $this->em->flush();

        return $nom;
    }

    /**
     * Crée l'intention de paiement et renvoie l'adresse de la page de paiement HelloAsso (valable 15 minutes).
     *
     * @param list<array{amount: int, date: string}> $echeances échéances suivantes (montants en centimes)
     * @param array<string, mixed>                  $payeur    firstName, lastName, email…
     * @param array<string, mixed>                  $metadata
     *
     * @return array{id: int, redirectUrl: string}
     */
    public function creerPaiement(ConnexionHelloAsso $c, string $libelle, int $total, int $premier, array $echeances, array $payeur, string $retour, string $erreur, string $abandon, array $metadata): array
    {
        $corps = [
            'totalAmount' => $total,
            'initialAmount' => $premier,
            'itemName' => mb_substr($libelle, 0, 250),
            'backUrl' => $abandon,
            'errorUrl' => $erreur,
            'returnUrl' => $retour,
            'containsDonation' => false,
            'payer' => array_filter($payeur),
            'metadata' => $metadata,
        ];
        if ($echeances) {
            $corps['terms'] = $echeances;
        }
        $reponse = $this->appeler($c, 'POST', '/v5/organizations/' . rawurlencode((string) $c->getSlug()) . '/checkout-intents', $corps);
        if (empty($reponse['id']) || empty($reponse['redirectUrl'])) {
            throw new ErreurHelloAsso('HelloAsso n\'a pas renvoyé de page de paiement.');
        }

        return ['id' => (int) $reponse['id'], 'redirectUrl' => (string) $reponse['redirectUrl']];
    }

    /**
     * État d'une intention de paiement : la clé « order » n'existe qu'une fois le paiement autorisé.
     *
     * @return array<string, mixed>
     */
    public function lirePaiement(ConnexionHelloAsso $c, int $id): array
    {
        return $this->appeler($c, 'GET', '/v5/organizations/' . rawurlencode((string) $c->getSlug()) . '/checkout-intents/' . $id);
    }

    /**
     * @param array<string, mixed>|null $corps
     *
     * @return array<string, mixed>
     */
    private function appeler(ConnexionHelloAsso $c, string $methode, string $chemin, ?array $corps = null): array
    {
        try {
            $reponse = $this->http->request($methode, self::adresse($c) . $chemin, array_filter([
                'auth_bearer' => $this->jeton($c),
                'json' => $corps,
                'timeout' => 20,
            ], fn ($v) => null !== $v));
            $code = $reponse->getStatusCode();
            $donnees = $code < 300 ? $reponse->toArray(false) : [];
        } catch (ExceptionInterface $e) {
            throw new ErreurHelloAsso('HelloAsso ne répond pas pour le moment. Réessayez dans quelques minutes.', 0, $e);
        }

        return match (true) {
            $code < 300 => $donnees,
            \in_array($code, [401, 403], true) => throw new ErreurHelloAsso('HelloAsso refuse l\'accès : vérifiez que ces identifiants ont bien le droit « Checkout » et appartiennent à cette association.'),
            404 === $code => throw new ErreurHelloAsso('Association introuvable sur HelloAsso : vérifiez son identifiant (la fin de l\'adresse de votre page HelloAsso).'),
            409 === $code => throw new ErreurHelloAsso('Votre association n\'est pas encore vérifiée par HelloAsso : terminez la vérification dans votre espace HelloAsso pour recevoir des paiements.'),
            default => throw new ErreurHelloAsso(\sprintf('HelloAsso a répondu par une erreur (%d).', $code)),
        };
    }

    /** Jeton d'accès valable : celui gardé, sinon renouvelé, sinon demandé avec les identifiants du club. */
    private function jeton(ConnexionHelloAsso $c): string
    {
        if ($jeton = $c->getAccessToken()) {
            return $jeton;
        }
        $parametres = ($refresh = $c->getRefreshToken())
            ? ['grant_type' => 'refresh_token', 'refresh_token' => $refresh, 'client_id' => $c->getClientId()]
            : ['grant_type' => 'client_credentials', 'client_id' => $c->getClientId(), 'client_secret' => $this->coffre->dechiffrer($c->getSecretChiffre()) ?? throw new ErreurHelloAsso('Le Client secret enregistré ne se lit plus : saisissez-le à nouveau.')];
        try {
            $reponse = $this->http->request('POST', self::adresse($c) . '/oauth2/token', ['body' => $parametres, 'timeout' => 20]);
            $code = $reponse->getStatusCode();
            $donnees = $reponse->toArray(false);
        } catch (ExceptionInterface $e) {
            throw new ErreurHelloAsso('HelloAsso ne répond pas pour le moment. Réessayez dans quelques minutes.', 0, $e);
        }
        if ($code >= 300 || empty($donnees['access_token'])) {
            if ($refresh) {
                // Jeton de renouvellement refusé : on repart des identifiants.
                $c->oublierVerification();

                return $this->jeton($c);
            }
            throw new ErreurHelloAsso('Identifiants refusés par HelloAsso : vérifiez le Client ID et le Client secret' . ($c->isTest() ? ' (compte de test)' : ' (pour un compte de test, cochez « Compte de test »)') . '.');
        }
        $c->setJetons((string) $donnees['access_token'], (int) ($donnees['expires_in'] ?? 1800), $donnees['refresh_token'] ?? null);
        $this->em->flush();

        return (string) $donnees['access_token'];
    }
}
