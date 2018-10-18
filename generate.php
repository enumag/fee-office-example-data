<?php

use Faker\Factory;
use Nette\Utils\Json;
use Ramsey\Uuid\Uuid;

require __DIR__ . '/vendor/autoload.php';

$faker = Factory::create();

function createBuilding(): object {
    global $faker;
    return (object) [
        'id' => Uuid::uuid4()->toString(),
        'name' => $faker->streetName,
    ];
}

function createAccountingOrganization(object $subject, string $name): object {
    return (object) [
        'id' => Uuid::uuid4()->toString(),
        'subjectId' => $subject->id,
        'name' => $name,
    ];
}

function createEntrance(object $building): object {
    global $faker;
    return (object) [
        'id' => Uuid::uuid4()->toString(),
        'buildingId' => $building->id,
        'address' => $building->name . ' ' . $faker->numberBetween(1, 20),
    ];
}

function createApartment(object $entrance, int $number): object {
    return (object) [
        'id' => Uuid::uuid4()->toString(),
        'entranceId' => $entrance->id,
        'apartmentNumber' => $number,
    ];
}

function createContract(
    object $apartment,
    string $accountingOrganizationId,
    string $superiorPartyId,
    string $subordinatePartyId,
    DateTimeImmutable $since,
    ?DateTimeImmutable $until
): object {
    return (object) [
        'id' => Uuid::uuid4()->toString(),
        'apartmentId' => $apartment->id,
        'accountingOrganizationId' => $accountingOrganizationId,
        'superiorPartyId' => $superiorPartyId,
        'subordinatePartyId' => $subordinatePartyId,
        'since' => $since->format(DateTimeImmutable::ATOM),
        'until' => $until ? $until->format(DateTimeImmutable::ATOM) : null,
    ];
}

function createContact(): object {
    global $faker;
    $isCompany = $faker->boolean;
    return (object) [
        'id' => Uuid::uuid4()->toString(),
        'firstName' => $isCompany ? null : $faker->firstName,
        'lastName' => $isCompany ? null : $faker->lastName,
        'company' => $isCompany ? $faker->company : null,
    ];
}

function createApartmentAttribute(string $name): object
{
    return (object) [
        'id' => Uuid::uuid4()->toString(),
        'name' => $name,
    ];
}

function createAttributeChange(object $apartmentAttribute, \DateTimeImmutable $date, int $value): object
{
    return (object) [
        'apartmentAttributeId' => $apartmentAttribute->id,
        'date' => $date->format(DateTimeImmutable::ATOM),
        'value' => $value,
    ];
}

function createFinancialAccountGroup(string $accountingOrganizationId, string $contactId): object
{
    return (object) [
        'id' => Uuid::uuid4()->toString(),
        'accountingOrganizationId' => $accountingOrganizationId,
        'contactId' => $contactId,
    ];
}

function createFinancialAccount(object $financialAccountGroup, string $contractId): object
{
    return (object) [
        'id' => Uuid::uuid4()->toString(),
        'financialAccountGroupId' => $financialAccountGroup,
        'contractId' => $contractId,
    ];
}

function createFeeRecipe(object $financialAccount, string $name, DateTimeImmutable $since, ?DateTimeImmutable $until): object
{
    global $faker;
    return (object) [
        'id' => Uuid::uuid4()->toString(),
        'financialAccountId' => $financialAccount->id,
        'name' => $name,
        'since' => $since,
        'until' => $until,
        'formula' => $faker->randomElement(
            [
                (string) $faker->numberBetween(10, 1000),
                'area * ' . $faker->numberBetween(5, 50),
                'body_count * ' . $faker->numberBetween(100, 500),
            ]
        )
    ];
}

function createFee(object $feeRecipe, DateTimeImmutable $since, DateTimeImmutable $until): object
{
    global $faker;
    return (object) [
        'id' => Uuid::uuid4()->toString(),
        'feeRecipeId' => $feeRecipe->id,
        'since' => $since->format(DateTimeImmutable::ATOM),
        'until' => $until->format(DateTimeImmutable::ATOM),
        'price' => $faker->numberBetween(0, 2000),
    ];
}

function createPayment(object $financialAccountGroup, DateTimeImmutable $date): object
{
    global $faker;
    return (object) [
        'id' => Uuid::uuid4()->toString(),
        'financialAccountGroupId' => $financialAccountGroup->id,
        'date' => $date->format(DateTimeImmutable::ATOM),
        'price' => $faker->numberBetween(10, 5000),
    ];
}


$data = (object) [];

for ($i = 1; $i <= 3; ++$i) {
    $data->buildings[] = createBuilding();
}

// entranceId / apartmentId => accountingOrganizationId
$accountingOrganizationMap = [];

// accountingOrganizationId => contactId
$accountingOrganizationSubjects = [];

$firstBuilding = true;
foreach ($data->buildings as $building) {
    // Usually there is just one accounting organization per building but sometimes there are more (usually per entrance).
    // For the demo data let's have 1 building with per-entrance accounting and the rest as normal.
    $accountingOrganizationPerEntrance = $firstBuilding;
    $firstBuilding = false;

    if (! $accountingOrganizationPerEntrance) {
        $data->contacts[] = $contact = createContact();
        $accountingOrganization = createAccountingOrganization($contact, $building->name);
        $accountingOrganizationSubjects[$accountingOrganization->id] = $accountingOrganization->subjectId;
    }

    for ($i = 1; $i <= $faker->numberBetween(1, 5); ++$i) {
        $data->entrances[] = $entrance = createEntrance($building);

        if ($accountingOrganizationPerEntrance) {
            $data->contacts[] = $contact = createContact();
            $accountingOrganization = createAccountingOrganization($contact, $entrance->address);
            $data->accountingOrganizations[] = $accountingOrganization;
            $accountingOrganizationSubjects[$accountingOrganization->id] = $accountingOrganization->subjectId;
        }

        $accountingOrganizationMap[$entrance->id] = $accountingOrganization->id;
    }
}

foreach ($data->entrances as $entrance) {
    for ($i = 1; $i <= $faker->numberBetween(1, 30); ++$i) {
        $data->apartments[] = $apartment = createApartment($entrance, $i);
        $accountingOrganizationMap[$apartment->id] = $accountingOrganizationMap[$entrance->id];
    }
}

// accountingOrganizationId => contactId[]
$apartmentOwnersPerAccountingOrganization = [];
// apartmentId => DateTimeImmutable
$startingDatePerApartment = [];
// contactId => contractId[]
$contractsPerOwner = [];
// contractId => apartmentId
$contractApartmentMap = [];

foreach ($data->apartments as $apartment) {
    $contractsCount = $faker->numberBetween(1, 3);
    $since = DateTimeImmutable::createFromMutable($faker->dateTimeBetween('-5 years', '-2 years'));
    $startingDatePerApartment[$apartment->id] = $since;

    for ($i = 1; $i <= $contractsCount; ++$i) {
        $accountingOrganizationId = $accountingOrganizationMap[$apartment->id];

        // Usually there is separate owner for each apartment but of course some people can own multiple apartments.
        // Here we have 20% chance for an existing owner to be chosen instead of creating a new one.
        $chooseExistingContact = $faker->numberBetween(1, 5) === 5 && isset($apartmentOwnersPerAccountingOrganization[$accountingOrganizationId]);
        if ($chooseExistingContact) {
            $contactId = $faker->randomElement($apartmentOwnersPerAccountingOrganization[$accountingOrganizationId]);
        } else {
            $data->contacts[] = $contact = createContact();
            $apartmentOwnersPerAccountingOrganization[$accountingOrganizationId][] = $contactId = $contact->id;
        }

        // Each apartment must have an active contract and there can be no spaces between contracts nor can they overlap.
        $isLastContract = $i === $contractsCount;
        $until = $isLastContract ? null : $since->modify('+' . $faker->numberBetween(30, 600) . ' days');

        $data->contracts[] = $contract = createContract(
            $apartment,
            $accountingOrganizationId,
            $accountingOrganizationSubjects[$accountingOrganizationId],
            $contactId,
            $since,
            $until
        );

        $contractApartmentMap[$contract->id] = $apartment->id;
        $contractsPerOwner[$contactId][] = $contract->id;

        if (! $isLastContract) {
            $since = $until->modify('+1 day');
        }
    }
}

$data->apartmentAttributes[] = $apartmentAttributeArea = createApartmentAttribute('Area');
$data->apartmentAttributes[] = $apartmentAttributeBodyCount = createApartmentAttribute('Body count');

foreach ($data->apartments as $apartment) {
    $apartment->attributeChanges[] = createAttributeChange(
        $apartmentAttributeArea,
        $startingDatePerApartment[$apartment->id],
        $faker->numberBetween(50, 100)
    );

    $date = $startingDatePerApartment[$apartment->id];
    for ($i = 1; $i <= $faker->numberBetween(1, 5); ++$i) {
        $apartment->attributeChanges[] = createAttributeChange(
            $apartmentAttributeBodyCount,
            $date,
            $faker->numberBetween(1, 6)
        );

        $date = $date->modify('+' . $faker->numberBetween(30, 600) . ' days');
    }
}

foreach ($apartmentOwnersPerAccountingOrganization as $accountingOrganizationId => $apartmentOwners) {
    foreach ($apartmentOwners as $contactId) {
        $data->financialAccountGroups[] = createFinancialAccountGroup($accountingOrganizationId, $contactId);
    }
}

foreach ($data->financialAccountGroups as $financialAccountGroup) {
    $contractId = $faker->randomElement($contractsPerOwner[$financialAccountGroup->contactId]);
    $data->financialAccounts[] = createFinancialAccount($financialAccountGroup, $contractId);
}

$feeRecipeTypes =  [
    'cold_water',
    'warm_water',
    'gas',
    'fund',
    'cleanup',
];

foreach ($data->financialAccounts as $financialAccount) {
    foreach ($feeRecipeTypes as $feeRecipeType) {
        $since = $startingDatePerApartment[$contractApartmentMap[$financialAccount->contractId]];

        $feeRecipeChangeLimit = $faker->numberBetween(1, 5);
        for ($i = 1; $i <= $feeRecipeChangeLimit; ++$i) {
            $until = $i === $feeRecipeChangeLimit ? null : $since->modify('+' . $faker->numberBetween(30, 600) . ' days');
            $data->feeRecipes[] = createFeeRecipe($financialAccount, $feeRecipeType, $since, $until);
            $since = $until ? $until->modify('+1 day') : null;
        }
    }
}

foreach ($data->feeRecipes as $feeRecipe) {
    /** @var DateTimeImmutable $since */
    $since = $feeRecipe->since;
    $endDate = $feeRecipe->until ?? (new DateTimeImmutable())->modify('last day of previous month');
    while ($since < $endDate) {
        $until = min($since->modify('last day of this month'), $endDate);
        $data->fee[] = createFee($feeRecipe, $since, $until);
        $since = $until->modify('+1 day');
    }
}

foreach ($data->financialAccountGroups as $financialAccountGroup) {
    $date = DateTimeImmutable::createFromMutable($faker->dateTimeBetween('-5 years', '-2 years'));
    for ($i = 1; $i <= $faker->numberBetween(10, 30); ++$i) {
        $data->payments[] = createPayment($financialAccountGroup, $date);
        $date = $date->modify('+' . $faker->numberBetween(10, 100) . ' days');
    }
}

echo Json::encode($data, Json::PRETTY);
