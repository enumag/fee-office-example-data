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

foreach ($data->apartments as $apartment) {
    $contractsCount = $faker->numberBetween(1, 3);
    $since = DateTimeImmutable::createFromMutable($faker->dateTimeBetween('-5 years', '-2 years'));
    for ($i = 1; $i <= $contractsCount; ++$i) {
        $accountingOrganizationId = $accountingOrganizationMap[$apartment->id];

        // Usually there is separate owner for each apartment but of course some people can own multiple apartments.
        // Here we have 20% chance for an existing owner to be chosen instead of creating a new one.
        $chooseExistingContact = $faker->numberBetween(1, 5) === 5;
        $contactId = null;
        if ($chooseExistingContact) {
            $contactId = $faker->randomElement($apartmentOwnersPerAccountingOrganization[$accountingOrganizationId]);
        }
        if (! $contactId) {
            $data->contacts[] = $contact = createContact();
            $apartmentOwnersPerAccountingOrganization[$accountingOrganizationId][] = $contactId = $contact->id;
        }

        // Each apartment must have an active contract and there can be no spaces between contracts nor can they overlap.
        $isLastContract = $i === $contractsCount;
        $until = $isLastContract ? null : $since->modify('+' . $faker->numberBetween(30, 600) . ' days');

        $data->contracts[] = createContract(
            $apartment,
            $accountingOrganizationId,
            $accountingOrganizationSubjects[$accountingOrganizationId],
            $contactId,
            $since,
            $until
        );

        if (! $isLastContract) {
            $since = $until->modify('+1 day');
        }
    }
}

echo Json::encode($data, Json::PRETTY);
