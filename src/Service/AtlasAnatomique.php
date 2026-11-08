<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Atlas anatomique de l'infirmerie : on localise une blessure sur le corps entier (face ou dos), puis on
 * désigne la structure touchée (muscle, os, ligament, tendon) d'une région. Le clic final ouvre
 * « Nouvelle blessure » du joueur, déjà remplie (localisation, membre, zone).
 *
 * Illustrations : OpenStax, « Anatomy and Physiology » (Rice University), via Wikimedia Commons, licences
 * CC BY 3.0 / 4.0 et CC BY-SA 4.0 (vues du corps entier) ; redimensionnées (public/images/atlas). Auteur,
 * licence et source de chaque image : config/atlas_credits.json, cités au bas des pages de l'atlas.
 * Positions des zones du corps entier en % de l'image : [gauche, haut, largeur, hauteur].
 */
class AtlasAnatomique
{
    /**
     * Zones du corps entier. « cote » : la région existe à droite et à gauche du joueur.
     * Vue de face : la droite du joueur est à gauche de l'image ; vue de dos : à droite.
     */
    public const CORPS = [
        'face' => [
            'image' => 'corps-face.jpg',
            'titre' => 'Vue de face',
            'zones' => [
                ['region' => 'tete', 'pos' => [42.5, 1, 10.5, 12]],
                ['region' => 'cou', 'pos' => [43.5, 13, 8, 4]],
                ['region' => 'epaule', 'cote' => 'droite', 'pos' => [33.5, 15.5, 9, 7]],
                ['region' => 'epaule', 'cote' => 'gauche', 'pos' => [52.5, 15.5, 9, 7]],
                ['region' => 'thorax', 'pos' => [41.5, 17, 12, 9.5]],
                ['region' => 'bras', 'cote' => 'droite', 'pos' => [32, 22.5, 7, 9.5]],
                ['region' => 'bras', 'cote' => 'gauche', 'pos' => [56, 22.5, 7, 9.5]],
                ['region' => 'coude', 'cote' => 'droite', 'pos' => [29.5, 32, 7, 4]],
                ['region' => 'coude', 'cote' => 'gauche', 'pos' => [58.5, 32, 7, 4]],
                ['region' => 'abdomen', 'pos' => [41.5, 26.5, 12, 14]],
                ['region' => 'avant-bras', 'cote' => 'droite', 'pos' => [27, 36, 7.5, 9]],
                ['region' => 'avant-bras', 'cote' => 'gauche', 'pos' => [61, 36, 7.5, 9]],
                ['region' => 'main', 'cote' => 'droite', 'pos' => [21.5, 45, 8, 10]],
                ['region' => 'main', 'cote' => 'gauche', 'pos' => [65.5, 45, 8, 10]],
                ['region' => 'hanche', 'cote' => 'droite', 'pos' => [38.5, 40.5, 8.5, 7]],
                ['region' => 'hanche', 'cote' => 'gauche', 'pos' => [48, 40.5, 8.5, 7]],
                ['region' => 'cuisse', 'cote' => 'droite', 'pos' => [38.5, 48, 9, 15.5]],
                ['region' => 'cuisse', 'cote' => 'gauche', 'pos' => [48, 48, 9, 15.5]],
                ['region' => 'genou', 'cote' => 'droite', 'pos' => [39.5, 63.5, 7.5, 5.5]],
                ['region' => 'genou', 'cote' => 'gauche', 'pos' => [48.5, 63.5, 7.5, 5.5]],
                ['region' => 'jambe', 'cote' => 'droite', 'pos' => [39.5, 69, 7.5, 12]],
                ['region' => 'jambe', 'cote' => 'gauche', 'pos' => [48.5, 69, 7.5, 12]],
                ['region' => 'cheville-pied', 'cote' => 'droite', 'pos' => [38.5, 81, 8.5, 9]],
                ['region' => 'cheville-pied', 'cote' => 'gauche', 'pos' => [48.5, 81, 8.5, 9]],
            ],
        ],
        'dos' => [
            'image' => 'corps-dos.jpg',
            'titre' => 'Vue de dos',
            'zones' => [
                ['region' => 'tete', 'pos' => [43, 2, 9.5, 10]],
                ['region' => 'cou', 'pos' => [43.5, 12, 8.5, 5]],
                ['region' => 'epaule', 'cote' => 'gauche', 'pos' => [34.5, 17, 9, 7]],
                ['region' => 'epaule', 'cote' => 'droite', 'pos' => [52, 17, 9, 7]],
                ['region' => 'dos', 'pos' => [41.5, 18, 12, 15]],
                ['region' => 'bras', 'cote' => 'gauche', 'pos' => [31.5, 24, 7, 10]],
                ['region' => 'bras', 'cote' => 'droite', 'pos' => [57, 24, 7, 10]],
                ['region' => 'lombaires', 'pos' => [41.5, 33, 12, 7.5]],
                ['region' => 'coude', 'cote' => 'gauche', 'pos' => [28.5, 34, 7, 4]],
                ['region' => 'coude', 'cote' => 'droite', 'pos' => [60, 34, 7, 4]],
                ['region' => 'avant-bras', 'cote' => 'gauche', 'pos' => [25.5, 37.5, 7.5, 8.5]],
                ['region' => 'avant-bras', 'cote' => 'droite', 'pos' => [62.5, 37.5, 7.5, 8.5]],
                ['region' => 'main', 'cote' => 'gauche', 'pos' => [20.5, 45.5, 8, 9.5]],
                ['region' => 'main', 'cote' => 'droite', 'pos' => [66.5, 45.5, 8, 9.5]],
                ['region' => 'fessier', 'cote' => 'gauche', 'pos' => [39.5, 40.5, 8.5, 8]],
                ['region' => 'fessier', 'cote' => 'droite', 'pos' => [48, 40.5, 8.5, 8]],
                ['region' => 'cuisse', 'cote' => 'gauche', 'pos' => [39.5, 48.5, 8.5, 13]],
                ['region' => 'cuisse', 'cote' => 'droite', 'pos' => [48, 48.5, 8.5, 13]],
                ['region' => 'genou', 'cote' => 'gauche', 'pos' => [40, 61.5, 7.5, 4]],
                ['region' => 'genou', 'cote' => 'droite', 'pos' => [48.5, 61.5, 7.5, 4]],
                ['region' => 'jambe', 'cote' => 'gauche', 'pos' => [40, 65.5, 7.5, 11.5]],
                ['region' => 'jambe', 'cote' => 'droite', 'pos' => [48.5, 65.5, 7.5, 11.5]],
                ['region' => 'cheville-pied', 'cote' => 'gauche', 'pos' => [39.5, 77, 8.5, 11]],
                ['region' => 'cheville-pied', 'cote' => 'droite', 'pos' => [48, 77, 8.5, 11]],
            ],
        ],
    ];

    /**
     * Régions : libellé (avec le côté, « Épaule droite »), membre, planches (titre, images) et structures
     * proposées, par famille.
     */
    public const REGIONS = [
        'tete' => [
            'libelle' => 'Tête', 'membre' => 'Tête',
            'planches' => [['titre' => 'Os du crâne', 'images' => ['crane-os.jpg']]],
            'structures' => [
                'Traumatismes' => ['Commotion cérébrale', 'Traumatisme crânien', 'Plaie du cuir chevelu', 'Arcade sourcilière'],
                'Os' => ['Os frontal', 'Os pariétal', 'Os temporal', 'Os occipital', 'Os nasal (nez)', 'Os zygomatique (pommette)', 'Maxillaire', 'Mandibule (mâchoire)'],
                'Muscles' => ['Frontal', 'Temporal', 'Masséter'],
            ],
        ],
        'cou' => [
            'libelle' => 'Cou', 'membre' => 'Tête',
            'planches' => [
                ['titre' => 'Muscles du cou (face)', 'images' => ['cou-muscles.jpg']],
                ['titre' => 'Muscles du cou (dos et côté)', 'images' => ['cou-dos-muscles.jpg']],
                ['titre' => 'Colonne vertébrale', 'images' => ['colonne-os.jpg']],
            ],
            'structures' => [
                'Muscles' => ['Sterno-cléido-mastoïdien', 'Trapèze (faisceau supérieur)', 'Scalènes', 'Splénius de la tête', 'Élévateur de la scapula'],
                'Os et articulations' => ['Vertèbres cervicales', 'Disque intervertébral cervical', 'Entorse cervicale (coup du lapin)'],
            ],
        ],
        'epaule' => [
            'libelle' => 'Épaule', 'membre' => 'Membre supérieur', 'cote' => true,
            'planches' => [
                ['titre' => 'Muscles de la ceinture scapulaire', 'images' => ['epaule-muscles-ceinture.jpg']],
                ['titre' => 'Muscles qui mobilisent l\'humérus', 'images' => ['epaule-muscles-humerus.jpg']],
                ['titre' => 'Os de l\'épaule', 'images' => ['epaule-os.jpg', 'omoplate-os.jpg']],
                ['titre' => 'Articulation de l\'épaule', 'images' => ['epaule-articulation.jpg']],
            ],
            'structures' => [
                'Muscles' => ['Deltoïde', 'Supra-épineux', 'Infra-épineux', 'Petit rond', 'Grand rond', 'Subscapulaire', 'Grand pectoral', 'Petit pectoral', 'Trapèze', 'Rhomboïdes', 'Dentelé antérieur', 'Grand dorsal', 'Coraco-brachial'],
                'Tendons et ligaments' => ['Coiffe des rotateurs', 'Tendon de la longue portion du biceps', 'Capsule articulaire', 'Bourrelet glénoïdien (labrum)', 'Ligament coraco-acromial', 'Ligament coraco-claviculaire', 'Ligaments acromio-claviculaires', 'Bourse sous-acromiale'],
                'Os et articulations' => ['Clavicule', 'Scapula (omoplate)', 'Acromion', 'Tête de l\'humérus', 'Articulation gléno-humérale (luxation)', 'Articulation acromio-claviculaire', 'Articulation sterno-claviculaire'],
            ],
        ],
        'bras' => [
            'libelle' => 'Bras', 'membre' => 'Membre supérieur', 'cote' => true,
            'planches' => [
                ['titre' => 'Muscles du bras et de l\'avant-bras', 'images' => ['bras-avant-bras-muscles.jpg']],
                ['titre' => 'Humérus et coude', 'images' => ['humerus-coude-os.jpg']],
            ],
            'structures' => [
                'Muscles' => ['Biceps brachial', 'Brachial', 'Coraco-brachial', 'Triceps brachial', 'Deltoïde (insertion)'],
                'Os' => ['Humérus (diaphyse)'],
            ],
        ],
        'coude' => [
            'libelle' => 'Coude', 'membre' => 'Membre supérieur', 'cote' => true,
            'planches' => [
                ['titre' => 'Articulation du coude', 'images' => ['coude-articulation.jpg']],
                ['titre' => 'Humérus et coude', 'images' => ['humerus-coude-os.jpg']],
            ],
            'structures' => [
                'Tendons et ligaments' => ['Ligament collatéral ulnaire (interne)', 'Ligament collatéral radial (externe)', 'Ligament annulaire', 'Capsule articulaire', 'Tendon distal du biceps', 'Tendon du triceps', 'Épicondyliens latéraux (tennis-elbow)', 'Épicondyliens médiaux (golf-elbow)', 'Bourse olécrânienne'],
                'Os et articulations' => ['Épicondyle latéral', 'Épicondyle médial', 'Olécrâne', 'Tête radiale', 'Luxation du coude'],
            ],
        ],
        'avant-bras' => [
            'libelle' => 'Avant-bras', 'membre' => 'Membre supérieur', 'cote' => true,
            'planches' => [
                ['titre' => 'Muscles du bras et de l\'avant-bras', 'images' => ['bras-avant-bras-muscles.jpg']],
                ['titre' => 'Radius et ulna', 'images' => ['avant-bras-os.jpg']],
            ],
            'structures' => [
                'Muscles' => ['Brachio-radial', 'Rond pronateur', 'Fléchisseur radial du carpe', 'Long palmaire', 'Fléchisseur ulnaire du carpe', 'Fléchisseur superficiel des doigts', 'Fléchisseur profond des doigts', 'Long extenseur radial du carpe', 'Court extenseur radial du carpe', 'Extenseur des doigts', 'Extenseur ulnaire du carpe', 'Supinateur', 'Long abducteur du pouce'],
                'Os' => ['Radius', 'Ulna (cubitus)', 'Radius distal (fracture du poignet)'],
            ],
        ],
        'main' => [
            'libelle' => 'Poignet et main', 'libelle_cote' => ['droite' => 'Main droite', 'gauche' => 'Main gauche'], 'membre' => 'Membre supérieur', 'cote' => true,
            'planches' => [
                ['titre' => 'Muscles de la main', 'images' => ['main-muscles.jpg']],
                ['titre' => 'Os du poignet et de la main', 'images' => ['main-os.jpg']],
                ['titre' => 'Canal carpien', 'images' => ['canal-carpien.jpg']],
            ],
            'structures' => [
                'Muscles et tendons' => ['Éminence thénar (pouce)', 'Éminence hypothénar', 'Interosseux', 'Lombricaux', 'Tendons fléchisseurs', 'Tendons extenseurs', 'Canal carpien'],
                'Ligaments' => ['Ligament collatéral ulnaire du pouce', 'Ligaments du poignet', 'Entorse d\'un doigt'],
                'Os' => ['Scaphoïde', 'Lunatum', 'Triquetrum', 'Pisiforme', 'Trapèze', 'Trapézoïde', 'Capitatum', 'Hamatum', 'Métacarpiens', 'Phalanges des doigts', 'Pouce'],
            ],
        ],
        'thorax' => [
            'libelle' => 'Thorax', 'membre' => 'Tronc',
            'planches' => [
                ['titre' => 'Muscles du thorax', 'images' => ['thorax-muscles.jpg']],
                ['titre' => 'Cage thoracique', 'images' => ['cage-thoracique-os.jpg']],
            ],
            'structures' => [
                'Muscles' => ['Grand pectoral', 'Petit pectoral', 'Intercostaux', 'Dentelé antérieur', 'Diaphragme'],
                'Os' => ['Sternum', 'Côtes', 'Cartilages costaux', 'Clavicule'],
            ],
        ],
        'abdomen' => [
            'libelle' => 'Abdomen', 'membre' => 'Tronc',
            'planches' => [['titre' => 'Muscles de l\'abdomen', 'images' => ['abdomen-muscles.jpg']]],
            'structures' => [
                'Muscles' => ['Droit de l\'abdomen (grand droit)', 'Oblique externe', 'Oblique interne', 'Transverse de l\'abdomen', 'Ligne blanche', 'Paroi abdominale (pubalgie)', 'Ligament inguinal (canal inguinal)'],
            ],
        ],
        'dos' => [
            'libelle' => 'Dos', 'membre' => 'Tronc',
            'planches' => [
                ['titre' => 'Muscles du cou et du dos', 'images' => ['dos-muscles.jpg']],
                ['titre' => 'Colonne vertébrale', 'images' => ['colonne-os.jpg']],
                ['titre' => 'Ligaments de la colonne', 'images' => ['colonne-ligaments.jpg']],
            ],
            'structures' => [
                'Muscles' => ['Trapèze', 'Grand dorsal', 'Rhomboïdes', 'Érecteurs du rachis (paravertébraux)', 'Dentelé postérieur'],
                'Os et ligaments' => ['Vertèbres thoraciques (dorsales)', 'Côtes (arrière)', 'Disque intervertébral', 'Ligament longitudinal postérieur', 'Ligament jaune'],
            ],
        ],
        'lombaires' => [
            'libelle' => 'Bas du dos', 'membre' => 'Tronc',
            'planches' => [
                ['titre' => 'Muscles du cou et du dos', 'images' => ['dos-muscles.jpg']],
                ['titre' => 'Colonne vertébrale', 'images' => ['colonne-os.jpg']],
                ['titre' => 'Ligaments de la colonne', 'images' => ['colonne-ligaments.jpg']],
            ],
            'structures' => [
                'Muscles' => ['Érecteurs du rachis (lombaires)', 'Carré des lombes', 'Grand dorsal (partie basse)'],
                'Os et ligaments' => ['Vertèbres lombaires', 'Disque intervertébral lombaire (hernie)', 'Sacrum', 'Coccyx', 'Articulation sacro-iliaque', 'Ligament longitudinal antérieur'],
            ],
        ],
        'hanche' => [
            'libelle' => 'Hanche', 'membre' => 'Membre inférieur', 'cote' => true,
            'planches' => [
                ['titre' => 'Muscles de la hanche et de la cuisse (face)', 'images' => ['fessiers-muscles-a.jpg']],
                ['titre' => 'Bassin', 'images' => ['bassin-os.jpg', 'os-coxal.jpg']],
                ['titre' => 'Ligaments du bassin', 'images' => ['bassin-ligaments.jpg']],
            ],
            'structures' => [
                'Muscles' => ['Psoas-iliaque (ilio-psoas)', 'Tenseur du fascia lata', 'Pectiné', 'Long adducteur', 'Court adducteur', 'Grand adducteur', 'Gracile', 'Sartorius (insertion haute)', 'Droit fémoral (insertion haute)'],
                'Ligaments et articulation' => ['Articulation coxo-fémorale', 'Labrum (bourrelet) de la hanche', 'Ligament ilio-fémoral', 'Ligament pubo-fémoral', 'Ligament inguinal', 'Pubalgie (symphyse pubienne)'],
                'Os' => ['Os coxal (ilium)', 'Épine iliaque antéro-supérieure', 'Crête iliaque', 'Pubis', 'Tête du fémur', 'Col du fémur', 'Grand trochanter'],
            ],
        ],
        'fessier' => [
            'libelle' => 'Fessier', 'membre' => 'Membre inférieur', 'cote' => true,
            'planches' => [
                ['titre' => 'Muscles fessiers et cuisse (dos)', 'images' => ['fessiers-muscles-b.jpg', 'fessiers-muscles-c.jpg']],
                ['titre' => 'Bassin', 'images' => ['bassin-os.jpg', 'os-coxal.jpg']],
                ['titre' => 'Ligaments du bassin', 'images' => ['bassin-ligaments.jpg']],
            ],
            'structures' => [
                'Muscles' => ['Grand fessier', 'Moyen fessier', 'Petit fessier', 'Piriforme (pyramidal)', 'Obturateurs', 'Jumeaux', 'Carré fémoral'],
                'Os et ligaments' => ['Ischion', 'Sacrum', 'Coccyx', 'Articulation sacro-iliaque', 'Ligaments sacro-tubéral et sacro-épineux', 'Grand trochanter (bursite)'],
            ],
        ],
        'cuisse' => [
            'libelle' => 'Cuisse', 'membre' => 'Membre inférieur', 'cote' => true,
            'planches' => [
                ['titre' => 'Muscles de la cuisse (face)', 'images' => ['fessiers-muscles-a.jpg']],
                ['titre' => 'Muscles de la cuisse (dos)', 'images' => ['fessiers-muscles-b.jpg', 'fessiers-muscles-c.jpg']],
                ['titre' => 'Fémur et rotule', 'images' => ['femur-rotule-os.jpg']],
            ],
            'structures' => [
                'Quadriceps (avant)' => ['Droit fémoral', 'Vaste latéral', 'Vaste médial', 'Vaste intermédiaire'],
                'Ischio-jambiers (arrière)' => ['Biceps fémoral', 'Semi-tendineux', 'Semi-membraneux'],
                'Adducteurs (intérieur)' => ['Long adducteur', 'Court adducteur', 'Grand adducteur', 'Gracile', 'Pectiné'],
                'Autres' => ['Sartorius', 'Tenseur du fascia lata', 'Bandelette ilio-tibiale', 'Fémur (diaphyse)'],
            ],
        ],
        'genou' => [
            'libelle' => 'Genou', 'membre' => 'Membre inférieur', 'cote' => true,
            'planches' => [
                ['titre' => 'Articulation du genou', 'images' => ['genou-articulation.jpg']],
                ['titre' => 'Lésions du genou', 'images' => ['genou-blessure.jpg']],
                ['titre' => 'Fémur et rotule', 'images' => ['femur-rotule-os.jpg']],
            ],
            'structures' => [
                'Ligaments' => ['Ligament croisé antérieur (LCA)', 'Ligament croisé postérieur (LCP)', 'Ligament collatéral médial (LLI)', 'Ligament collatéral latéral (LLE)', 'Capsule articulaire'],
                'Ménisques et tendons' => ['Ménisque médial (interne)', 'Ménisque latéral (externe)', 'Tendon rotulien (patellaire)', 'Tendon quadricipital', 'Patte d\'oie', 'Poplité', 'Bourses du genou'],
                'Os' => ['Rotule (patella)', 'Condyles du fémur', 'Plateau tibial', 'Tubérosité tibiale (Osgood-Schlatter)', 'Tête de la fibula', 'Cartilage du genou'],
            ],
        ],
        'jambe' => [
            'libelle' => 'Jambe', 'membre' => 'Membre inférieur', 'cote' => true,
            'planches' => [
                ['titre' => 'Muscles de la jambe (face)', 'images' => ['jambe-muscles-a.jpg']],
                ['titre' => 'Muscles de la jambe (dos)', 'images' => ['jambe-muscles-b.jpg', 'jambe-muscles-c.jpg']],
                ['titre' => 'Tibia et fibula', 'images' => ['jambe-os.jpg']],
            ],
            'structures' => [
                'Mollet (arrière)' => ['Gastrocnémien médial (jumeau interne)', 'Gastrocnémien latéral (jumeau externe)', 'Soléaire', 'Plantaire', 'Tibial postérieur', 'Long fléchisseur des orteils', 'Long fléchisseur de l\'hallux'],
                'Avant et côté' => ['Tibial antérieur', 'Long extenseur des orteils', 'Long extenseur de l\'hallux', 'Long fibulaire', 'Court fibulaire'],
                'Os' => ['Tibia', 'Fibula (péroné)', 'Périoste du tibia (périostite)'],
            ],
        ],
        'cheville-pied' => [
            'libelle' => 'Cheville et pied', 'libelle_cote' => ['droite' => 'Cheville droite', 'gauche' => 'Cheville gauche'], 'membre' => 'Membre inférieur', 'cote' => true,
            'planches' => [
                ['titre' => 'Articulations de la cheville et du pied', 'images' => ['cheville-articulation.jpg']],
                ['titre' => 'Os du pied', 'images' => ['pied-os.jpg']],
                ['titre' => 'Muscles du pied', 'images' => ['pied-muscles.jpg']],
            ],
            'structures' => [
                'Ligaments de la cheville' => ['Ligament talo-fibulaire antérieur', 'Ligament calcanéo-fibulaire', 'Ligament talo-fibulaire postérieur', 'Ligament deltoïdien (interne)', 'Ligaments tibio-fibulaires (syndesmose)'],
                'Tendons' => ['Tendon d\'Achille (calcanéen)', 'Tendons fibulaires', 'Tendon tibial postérieur', 'Aponévrose plantaire'],
                'Os' => ['Malléole latérale (externe)', 'Malléole médiale (interne)', 'Talus (astragale)', 'Calcanéus', 'Naviculaire', 'Cuboïde', 'Cunéiformes', 'Métatarsiens', '5e métatarsien', 'Phalanges des orteils', 'Gros orteil (hallux)'],
                'Muscles' => ['Muscles plantaires', 'Abducteur de l\'hallux', 'Court fléchisseur des orteils'],
            ],
        ],
    ];

    public function __construct(
        #[Autowire('%kernel.project_dir%/config/atlas_credits.json')]
        private readonly string $fichierCredits,
    ) {
    }

    /**
     * Crédits des images affichées (auteur, licence, source), pour la mention obligatoire des licences CC BY.
     *
     * @param string[] $images
     *
     * @return array<string, array{titre: string, licence: string, auteur: string, source: string}>
     */
    public function credits(array $images): array
    {
        $tous = json_decode((string) @file_get_contents($this->fichierCredits), true) ?: [];

        // Une seule mention par auteur et licence (deux planches OpenStax CC BY 3.0 : une mention).
        $credits = [];
        foreach (array_intersect_key($tous, array_flip($images)) as $fichier => $c) {
            $credits[$c['auteur'] . '|' . $c['licence']] ??= $c;
        }

        return $credits;
    }

    /** Localisation enregistrée sur la blessure : « Épaule droite », « Main gauche », « Abdomen »... */
    public static function localisation(string $region, ?string $cote): string
    {
        $r = self::REGIONS[$region];
        if (!($r['cote'] ?? false) || !\in_array($cote, ['droite', 'gauche'], true)) {
            return $r['libelle'];
        }

        return $r['libelle_cote'][$cote] ?? \sprintf('%s %s', $r['libelle'], self::accordCote($r['libelle'], $cote));
    }

    /** Adjectif accordé : « Bras droit », « Épaule droite », « Avant-bras gauche ». */
    private static function accordCote(string $libelle, string $cote): string
    {
        $feminins = ['Épaule', 'Hanche', 'Cuisse', 'Jambe', 'Main', 'Cheville'];
        $feminin = \in_array(explode(' ', $libelle)[0], $feminins, true);

        return 'droite' === $cote ? ($feminin ? 'droite' : 'droit') : ($feminin ? 'gauche' : 'gauche');
    }
}
