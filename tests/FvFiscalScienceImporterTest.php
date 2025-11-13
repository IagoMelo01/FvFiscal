<?php
require __DIR__ . '/bootstrap.php';

function ensure($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

class DummyDB
{
    public function escape($value)
    {
        return (string) $value;
    }

    public function query($sql)
    {
        return false;
    }

    public function fetch_object($res)
    {
        return null;
    }

    public function free($res)
    {
    }

    public function lasterror()
    {
        return 'stub error';
    }

    public function jdate($value)
    {
        return $value;
    }
}

class TestSociete extends Societe
{
    public $created = false;

    public function create($user)
    {
        $result = parent::create($user);
        $this->created = $result > 0;

        return $result;
    }
}

class TestImporter extends FvFiscalScienceImporter
{
    public $existing = array();

    /** @var TestSociete|null */
    public $createdSociete;

    protected function findThirdpartyIdByDocument($document)
    {
        if (isset($this->existing[$document])) {
            return $this->existing[$document];
        }

        return 0;
    }

    protected function instantiateSociete()
    {
        $this->createdSociete = new TestSociete(null);

        return $this->createdSociete;
    }

    public function resolveForTest(User $user, array $data)
    {
        return $this->resolveThirdparty($user, $data);
    }
}

function createImporter()
{
    global $conf, $langs;

    $conf = new Conf();
    $langs = null;

    $db = new DummyDB();

    return array(new TestImporter($db), $db);
}

function createUser($db)
{
    $user = new User($db);
    $user->id = 1;

    return $user;
}

function testReuseExistingThirdparty()
{
    list($importer, $db) = createImporter();
    $importer->existing['12345678901234'] = 99;
    $user = createUser($db);

    $result = $importer->resolveForTest($user, array('cnpj' => '12.345.678/9012-34'));

    ensure($result === 99, 'Should reuse existing thirdparty');
    ensure($importer->createdSociete === null, 'No Societe should be created when reusing');
}

function testCreatesSupplierThirdparty()
{
    Societe::$sequence = 0;

    list($importer, $db) = createImporter();
    $user = createUser($db);

    $payload = array(
        'cnpj' => '12.345.678/9012-34',
        'issuer_name' => 'ACME LTDA',
        'email' => 'financeiro@acme.test',
        'address' => array(
            'street' => 'Rua das Flores',
            'number' => '123',
            'city' => 'São Paulo',
            'state' => 'SP',
            'zip' => '01234-567',
        ),
    );

    $result = $importer->resolveForTest($user, $payload);

    ensure($result === 1, 'Should create new thirdparty');
    ensure($importer->createdSociete instanceof TestSociete, 'Created Societe should be tracked');
    $societe = $importer->createdSociete;
    ensure($societe->created === true, 'Societe should be created');
    ensure($societe->fournisseur === 1, 'Societe must be marked as supplier');
    ensure($societe->client === 0, 'Societe should not be client');
    ensure($societe->tva_intra === '12345678901234', 'CNPJ must populate tva_intra');
    ensure($societe->idprof2 === '12345678901234', 'CNPJ must populate idprof2');
    ensure(strpos($societe->note_private, 'Focus Science importer') !== false, 'Auto note must be applied');
    ensure($societe->address === 'Rua das Flores 123', 'Address should combine street and number');
    ensure($societe->zip === '01234-567', 'ZIP code should be copied');
    ensure($societe->town === 'São Paulo', 'Town should be mapped');
    ensure($societe->state_code === 'SP', 'State should be captured');
    ensure($societe->email === 'financeiro@acme.test', 'Email should be copied');
    ensure(!empty($societe->code_fournisseur), 'Supplier code must be generated');
}

function testNormalizeCpfReuse()
{
    list($importer, $db) = createImporter();
    $importer->existing['12345678901'] = 50;
    $user = createUser($db);

    $result = $importer->resolveForTest($user, array('cpf' => '123.456.789-01'));

    ensure($result === 50, 'CPF lookup should be normalized');
}

testReuseExistingThirdparty();
testCreatesSupplierThirdparty();
testNormalizeCpfReuse();

echo "All importer tests passed\n";
