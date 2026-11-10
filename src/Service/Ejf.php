<?php
declare(strict_types=1);
/*
 * This file is part of the CitOmni framework.
 * Low overhead, high performance, ready for anything.
 *
 * For more information, visit https://github.com/citomni
 *
 * Copyright (c) 2012-present Lars Grove Mortensen
 * SPDX-License-Identifier: MIT
 *
 * For full copyright, trademark, and license information,
 * please see the LICENSE file distributed with this source code.
 */

namespace CitOmni\DanishPublicSectorData\Service;

use CitOmni\DanishPublicSectorData\Enum\DatafordelerAuthentication;
use CitOmni\DanishPublicSectorData\Enum\EjfOwnerType;
use CitOmni\DanishPublicSectorData\Enum\EjfPartyType;
use CitOmni\DanishPublicSectorData\Exception\InvalidResponseException;
use CitOmni\DanishPublicSectorData\Support\DatafordelerClient;
use CitOmni\Kernel\Service\BaseService;


/**
 * Ejf: Service that reads the ownerships and ownership changes of Danish properties, and the administrators of owners, from Ejerfortegnelsen (EJF).
 *
 * Gives host apps what EJF registers about who owns and has owned a property and how it changed
 * hands, by BFE number or by the CVR number of a company owner, and who administers a person or
 * a company, such as the estate of a deceased owner, as package-owned arrays: Dates become
 * Danish calendar dates, EJF's placeholders for an unknown date become null, and the upstream
 * GraphQL names stay inside.
 * Authentication and transport belong to DatafordelerClient; what an ownership, a transfer or an
 * administration means for a case, such as who owned a home on a given day or which estate an
 * administrator handles, belongs to the caller.
 *
 * Rules:
 * - Query only CustomEjerskabBegraenset with its person and owner-information joins, Ejerskifte
 *   and Handelsoplysninger, and CustomPersonEllerVirksomhedsadminiBegraenset with its person and
 *   owner-information joins and the CustomAlternativAdresseBegraenset of that owner information.
 *   Datafordeler refuses a query as a whole when it touches an entity outside the host app's
 *   access, so another entity or join needs a query of its own.
 * - Return what EJF holds; never fill a value in from another register.
 *
 * @phpstan-type EjfShare array{numerator:int,denominator:int}
 * @phpstan-type EjfAddress array{
 *     line:?string,
 *     street:?string,
 *     houseNumber:?string,
 *     floor:?string,
 *     door:?string,
 *     building:?string,
 *     city:?string,
 *     postalCode:?string,
 *     postalDistrict:?string,
 *     municipalityCode:?string,
 *     municipalityName:?string,
 *     roadCode:?string,
 *     formatted:?string
 * }
 * @phpstan-type EjfForeignAddress array{lines:list<string>,country:?string,countryCode:?string}
 * @phpstan-type EjfPerson array{
 *     id:string,
 *     status:string,
 *     statusDate:string,
 *     statusDateUncertain:bool,
 *     birthDate:string,
 *     birthDateUncertain:bool,
 *     name:?string,
 *     protections:list<string>,
 *     address:EjfAddress|null,
 *     addressingName:?string,
 *     foreignAddress:EjfForeignAddress|null
 * }
 * @phpstan-type EjfOtherOwner array{name:?string,addressLines:list<string>,countryCode:?string}
 * @phpstan-type EjfOwnership array{
 *     id:string,
 *     bfeNumber:int,
 *     status:string,
 *     current:bool,
 *     validFrom:?string,
 *     ownershipCode:string,
 *     share:EjfShare|null,
 *     registeredShare:EjfShare|null,
 *     primaryContact:bool,
 *     businessEvent:?string,
 *     businessProcess:?string,
 *     ownerType:string,
 *     person:EjfPerson|null,
 *     companyCvr:?string,
 *     other:EjfOtherOwner|null
 * }
 * @phpstan-type EjfTrade array{
 *     id:string,
 *     totalPrice:?float,
 *     cashPrice:?float,
 *     movablesPrice:?float,
 *     currency:?string,
 *     contractDate:?string,
 *     disposalDate:?string,
 *     paymentDate:?string,
 *     buildings:?string,
 *     bfeNumbers:list<int>
 * }
 * @phpstan-type EjfTransfer array{
 *     id:string,
 *     bfeNumber:int,
 *     takeoverDate:?string,
 *     filedDate:?string,
 *     method:?string,
 *     businessEvent:?string,
 *     businessProcess:?string,
 *     conditional:?bool,
 *     deadline:?string,
 *     documentId:?string,
 *     trade:EjfTrade|null
 * }
 * @phpstan-type EjfPersonDates array{id:string,status:string,statusDate:string,birthDate:string}
 * @phpstan-type EjfAlternativeAddress array{status:string,current:bool,lines:list<string>,countryCode:?string}
 * @phpstan-type EjfAdministratorOther array{
 *     name:?string,
 *     addressLines:list<string>,
 *     countryCode:?string,
 *     alternativeAddress:EjfAlternativeAddress|null
 * }
 * @phpstan-type EjfAdministrationEntry array{
 *     id:string,
 *     objectId:?int,
 *     importSequence:int,
 *     status:string,
 *     current:bool,
 *     registeredAt:string,
 *     validFrom:?string,
 *     businessEvent:?string,
 *     businessProcess:?string,
 *     administeredType:string,
 *     administeredPerson:EjfPersonDates|null,
 *     administeredCvr:?string,
 *     administeredOtherId:?string
 * }
 * @phpstan-type EjfAdministration array{
 *     id:string,
 *     objectId:?int,
 *     importSequence:int,
 *     status:string,
 *     current:bool,
 *     registeredAt:string,
 *     validFrom:?string,
 *     businessEvent:?string,
 *     businessProcess:?string,
 *     administeredType:string,
 *     administeredPerson:EjfPersonDates|null,
 *     administeredCvr:?string,
 *     administeredOtherId:?string,
 *     administratorType:string,
 *     administratorPerson:EjfPerson|null,
 *     administratorCvr:?string,
 *     administratorPNumber:?string,
 *     administratorOtherId:?string,
 *     administratorOther:EjfAdministratorOther|null
 * }
 */
class Ejf extends BaseService {

	/** Maximum number of BFE numbers in one in filter. */
	private const int BFE_BATCH_SIZE = 100; // Datafordeler accepts at most 100 values in an in filter.

	/** Number of ownerships or transfers in one page. */
	private const int PAGE_SIZE = 100;

	/** Maximum number of the transfers of a trade that are read to list the properties of the trade. */
	private const int TRADE_TRANSFER_LIMIT = 100;

	/** Maximum number of object ids in one in filter, and the page size of a lookup by object ids. */
	private const int OBJECT_ID_BATCH_SIZE = 100; // Datafordeler accepts at most 100 values in an in filter.

	/** Number of administration entries in one page of a lookup by import batches or registration time. */
	private const int ENTRY_PAGE_SIZE = 500;

	/** Largest import batch number that a range accepts: The largest GraphQL Int. */
	private const int MAX_IMPORT_SEQUENCE = 2147483647;

	/** BFE number or object id accepted for lookup: Digits without leading zeroes. */
	private const string BFE_NUMBER_PATTERN = '/^[1-9][0-9]*$/D'; // Numbers become GraphQL Long literals, which cannot start with 0.

	/** CVR number accepted for lookup: Eight digits, the first of them 1-9. */
	private const string CVR_NUMBER_PATTERN = '/^[1-9][0-9]{7}$/D'; // Numbers become GraphQL Long literals, which cannot start with 0.

	/** Status of a current ownership, administration or alternative address, and of a transfer that was not rolled back. */
	private const string CURRENT_STATUS = 'gældende';

	/** Timezone of the calendar dates that EJF stores as instants. */
	private const string TIMEZONE = 'Europe/Copenhagen';

	/** Instants in UTC that EJF stores for an unknown date: The start of the year 1, and Danish midnight of 1 January 1800 and of 1 January 1970. */
	private const array PLACEHOLDER_INSTANTS = ['0001-01-01 00:00:00', '1799-12-31 23:00:00', '1969-12-31 23:00:00'];

	/** Instant as EJF writes it: A date and time with an explicit offset. */
	private const string INSTANT_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D';

	/** Selection of a CPR person that normalizePerson() reads, for a join at the depth of the fields of a root node. */
	private const string PERSON_SELECTION = <<<'GRAPHQL'
{
				id
				status
				statusdato
				statusdatoUsikkerhedsmarkering
				foedselsdato
				foedselsdatoUsikkerhedsmarkering
				navn {
					navn
				}
				beskyttelser {
					beskyttelsestype
				}
				standardadresse
				cprAdresse {
					vejadresseringsnavn
					husnummer
					etage
					sidedoer
					bygningsnummer
					bynavn
					postnummer
					postdistrikt
					cprKommunekode
					cprKommunenavn
					cprVejkode
				}
				adresseoplysninger {
					adresseringsnavn
				}
				udrejseIndrejse {
					udlandsadresselinje1
					udlandsadresselinje2
					udlandsadresselinje3
					udlandsadresselinje4
					udlandsadresselinje5
					cprLandUdrejse {
						navn
						landekode
					}
				}
			}
GRAPHQL;

	/** Selection of the administrator without CPR or CVR number of an administration, and the identifiers of the other kinds, that normalizeAdministration() reads; the person join follows it. */
	private const string ADMINISTRATOR_SELECTION = <<<'GRAPHQL'

			virksomhedCVRNr
			produktionsenhedPNr
			personVirksomhedOplysLokalId
			personEllerVirksomhedsadministratorErPersonEllerVirksomhedsoplysninger {
				navn
				adresselinje1
				adresselinje2
				adresselinje3
				adresselinje4
				adresselinje5
				adresselinje6
				adresselinje7
				adresselinje8
				adresselinje9
				adresselinje10
				landeKodeNumerisk
				alternativAdresseLokalId_23_AlternativAdresseBegraenset_id_lokalId_ref {
					status
					adresselinje1
					adresselinje2
					adresselinje3
					adresselinje4
					adresselinje5
					adresselinje6
					adresselinje7
					adresselinje8
					adresselinje9
					adresselinje10
					landeKodeNumerisk
				}
			}
GRAPHQL;

	/** Datafordeler client that authenticates with the Shared Secret. */
	private DatafordelerClient $datafordeler;

	/** Datafordeler service that serves EJF. */
	private string $service;

	/** Version of that service. */
	private string $version;

	/** Timezone of EJF's calendar dates. */
	private \DateTimeZone $timezone;


	/**
	 * Read the EJF endpoint from cfg and create the Datafordeler client.
	 *
	 * @return void
	 * @throws \UnexpectedValueException  When cfg danish_public_sector_data.ejf.service or .version is empty, or the Datafordeler cfg is invalid.
	 * @see \CitOmni\Kernel\Service\BaseService::__construct()  Calls it once per instance.
	 */
	protected function init(): void {
		$cfg = $this->app->cfg->danish_public_sector_data->ejf;

		$this->service = (string)$cfg->service;
		$this->version = (string)$cfg->version;

		if ($this->service === '' || $this->version === '') {
			throw new \UnexpectedValueException('Cfg danish_public_sector_data.ejf.service and danish_public_sector_data.ejf.version must not be empty.');
		}

		$this->datafordeler = new DatafordelerClient($this->app, DatafordelerAuthentication::SharedSecret);
		$this->timezone = new \DateTimeZone(self::TIMEZONE);
	}


	// ----------------------------------------------------------------
	// Lookups
	// ----------------------------------------------------------------

	/**
	 * Return the ownerships of properties, current and former, by BFE number.
	 *
	 * Behavior:
	 * - One entry per distinct BFE number, keyed by that number in the order the numbers first
	 *   occur. An entry lists the ownerships of the property oldest first by validFrom, those
	 *   without a known validFrom first, then by id. It is empty when EJF has no ownership of it.
	 * - current is true for an ownership with status gældende. Former ownerships, with status
	 *   historisk, are in the list too.
	 * - validFrom is the day the ownership took effect in EJF, as YYYY-MM-DD, or null for EJF's
	 *   placeholder for an unknown date. It is not always the day the owner took over.
	 * - share is the actual share (faktisk ejerandel) and registeredShare the share in the land
	 *   register (tinglyst ejerandel), as EJF holds them, unreduced; null when EJF has none.
	 * - ownerType is an EjfOwnerType value: person when the ownership has a person, otherwise
	 *   company when it has a CVR number, otherwise other when it has owner information, and
	 *   unknown without any of them. person holds the CPR data of the person, companyCvr the CVR
	 *   number, and other the name and address lines of an owner without CPR or CVR number; each
	 *   is null when EJF has none.
	 * - A person with name and address protection has name, address and addressingName null.
	 *   address is null for a person without an address in Denmark, such as a deceased or
	 *   emigrated person; foreignAddress holds the address abroad of an emigrated person.
	 * - address.line is the street, house number, floor and door as CPR writes them, and
	 *   address.formatted that line with the city and the postcode.
	 * - Sends the numbers in batches of up to 100, one request per page of up to 100 ownerships.
	 *   Every request of one call uses the same effect time: the time of the call.
	 *
	 * Notes:
	 * - EJF answers with former ownerships at every effect time, so the time of the call does not
	 *   narrow the list to the owners at that time.
	 *
	 * Typical usage:
	 *   $ownerships = $this->app->ejf->getOwnerships([4268969]);
	 *
	 * @param  array<array-key,int|string>  $bfeNumbers  BFE numbers as ints or strings of digits without leading zeroes. A repeated number is looked up once.
	 * @return array<int,list<EjfOwnership>>  Ownerships keyed by BFE number.
	 * @throws \InvalidArgumentException  When a BFE number is not a positive integer without leading zeroes.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a remote lookup fails or is malformed.
	 */
	public function getOwnerships(array $bfeNumbers): array {
		$ownerships = $this->rowsByBfe(
			$bfeNumbers,
			'EJFCustom_EjerskabBegraenset',
			self::ownershipsQuery(...),
			$this->normalizeOwnership(...)
		);

		foreach ($ownerships as $bfeNumber => $rows) {
			$ownerships[$bfeNumber] = self::oldestFirst($rows);
		}

		return $ownerships;
	}


	/**
	 * Return the ownerships of one company owner, current and former, by its CVR number.
	 *
	 * Finds the properties that a company owns or has owned without knowing their BFE numbers.
	 * The other owners and the transfers of those properties belong to getOwnerships() and
	 * getTransfers().
	 *
	 * Behavior:
	 * - One entry per property, keyed by BFE number in ascending order. An entry lists the
	 *   company's ownerships of the property in the order and shape of getOwnerships(), with
	 *   companyCvr set to the requested number. Empty when EJF has no ownership of the company.
	 * - Sends one request per page of up to 100 ownerships. Every request of one call uses the
	 *   same effect time: the time of the call.
	 *
	 * Notes:
	 * - EJF answers with former ownerships at every effect time, so the list holds the properties
	 *   that the company has owned as well as those it owns now. A former ownership is the
	 *   version that ended it: Its validFrom is the day it ended, not the day it began.
	 *
	 * Typical usage:
	 *   $ownerships = $this->app->ejf->getCompanyOwnerships('12345678');
	 *
	 * @param  int|string  $cvrNumber  Eight digits, the first of them 1-9.
	 * @return array<int,list<EjfOwnership>>  Ownerships keyed by BFE number.
	 * @throws \InvalidArgumentException  When the CVR number does not have eight digits or starts with 0.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When the remote lookup fails, or the answer is malformed or holds an ownership of another owner.
	 * @see \CitOmni\DanishPublicSectorData\Service\Ejf::getOwnerships()  The shape and order of an ownership.
	 */
	public function getCompanyOwnerships(int|string $cvrNumber): array {
		$number = \is_int($cvrNumber) ? (string)$cvrNumber : $cvrNumber;
		if (!\preg_match(self::CVR_NUMBER_PATTERN, $number)) {
			throw new \InvalidArgumentException('CVR number must be an int or a string of exactly eight digits, the first of them 1-9.');
		}

		$effectiveAt = \gmdate('Y-m-d\\TH:i:s\\Z');
		$rows = [];

		$nodes = $this->connectionNodes(
			'EJFCustom_EjerskabBegraenset',
			static fn(?string $after): string => self::companyOwnershipsQuery((int)$number, $effectiveAt, $after)
		);

		foreach ($nodes as $node) {
			$row = $this->normalizeOwnership($node);
			if ($row['companyCvr'] !== $number) {
				throw new InvalidResponseException('EJF response contains an ownership of another owner.');
			}

			// A row repeated on a later page is the same registration; the first one wins.
			$rows[$row['bfeNumber']][$row['id']] ??= $row;
		}

		\ksort($rows, \SORT_NUMERIC);

		foreach ($rows as $bfeNumber => $ownerships) {
			$rows[$bfeNumber] = self::oldestFirst($ownerships);
		}

		return $rows;
	}


	/**
	 * Return the ownership changes of properties by BFE number, with their trades.
	 *
	 * Behavior:
	 * - One entry per distinct BFE number, keyed by that number in the order the numbers first
	 *   occur. An entry lists the transfers of the property oldest first by takeoverDate, then by
	 *   filedDate, those without a known date first, then by id. It is empty when EJF has none.
	 * - Only transfers with status gældende: Rolled-back registrations (tilbagerullet) are left out.
	 * - takeoverDate (overtagelsesdato) and filedDate (anmeldelsesdato) are YYYY-MM-DD. Either is
	 *   null for EJF's placeholder for an unknown date, and filedDate also when EJF has none.
	 * - trade holds the trade information (Handelsoplysninger) when EJF has it. One trade can
	 *   cover several properties for one price; trade.bfeNumbers lists them, at most 100.
	 * - Amounts are in trade.currency. An amount, and the currency, is null when EJF has none.
	 * - Sends the numbers in batches of up to 100, one request per page of up to 100 transfers.
	 *   Every request of one call uses the same effect time: the time of the call.
	 *
	 * Notes:
	 * - The conversion from the former ownership register left some trades twice, with the same
	 *   takeover date, method and price. The list keeps both.
	 *
	 * Typical usage:
	 *   $transfers = $this->app->ejf->getTransfers([4268969]);
	 *
	 * @param  array<array-key,int|string>  $bfeNumbers  BFE numbers as ints or strings of digits without leading zeroes. A repeated number is looked up once.
	 * @return array<int,list<EjfTransfer>>  Transfers keyed by BFE number.
	 * @throws \InvalidArgumentException  When a BFE number is not a positive integer without leading zeroes.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a remote lookup fails or is malformed.
	 */
	public function getTransfers(array $bfeNumbers): array {
		$transfers = $this->rowsByBfe(
			$bfeNumbers,
			'EJF_Ejerskifte',
			self::transfersQuery(...),
			$this->normalizeTransfer(...)
		);

		foreach ($transfers as $bfeNumber => $rows) {
			\usort($rows, static fn(array $a, array $b): int => [$a['takeoverDate'] ?? '', $a['filedDate'] ?? '', $a['id']] <=> [$b['takeoverDate'] ?? '', $b['filedDate'] ?? '', $b['id']]);
			$transfers[$bfeNumber] = $rows;
		}

		return $transfers;
	}


	// ----------------------------------------------------------------
	// Administrations
	// ----------------------------------------------------------------

	/**
	 * Return administrations with their administrator, by object id.
	 *
	 * An administration (CustomPersonEllerVirksomhedsadminiBegraenset) names who administers a
	 * person or a company, such as the estate of a deceased person. EJF has no person filter for
	 * it, so a host app finds the object ids among the entries of the import batches or of a
	 * registration period, and reads the administrators of those it needs here.
	 *
	 * Behavior:
	 * - One entry per object id that EJF has an administration of, keyed by object id in the
	 *   order the ids first occur. An id without an administration has no entry.
	 * - An entry holds the fields of getAdministrationEntries() and the administrator.
	 *   administratorType is an EjfPartyType value: person when the administration has a
	 *   person, otherwise company when it has a CVR number, production_unit when it has a
	 *   P-number, other when it names an administrator without CPR or CVR number, and unknown
	 *   without any of them. The CVR number, the P-number and the id of the owner information
	 *   are returned whatever the type.
	 * - administratorPerson holds the CPR data of the person in the shape of the person of an
	 *   ownership. administratorOther holds the name and address lines of an administrator
	 *   without CPR or CVR number, and alternativeAddress the address for post to it, with
	 *   current true for status gældende; alternativeAddress is null when EJF names none.
	 * - Sends the ids in batches of up to 100, one request each. Every request of one call uses
	 *   the same effect time: the time of the call.
	 *
	 * Typical usage:
	 *   $administrations = $this->app->ejf->getAdministrations([627801]);
	 *
	 * @param  array<array-key,int|string>  $objectIds  Object ids as ints or strings of digits without leading zeroes. A repeated id is looked up once.
	 * @return array<int,EjfAdministration>  Administrations keyed by object id.
	 * @throws \InvalidArgumentException  When an object id is not a positive integer without leading zeroes.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a remote lookup fails, or an answer is malformed or holds an administration that was not requested.
	 * @see \CitOmni\DanishPublicSectorData\Service\Ejf::getAdministrationEntries()  The fields that an administration shares with an entry.
	 * @see \CitOmni\DanishPublicSectorData\Service\Ejf::getOwnerships()  The shape of a person.
	 */
	public function getAdministrations(array $objectIds): array {
		return $this->administrationsByObjectId($objectIds, true);
	}


	/**
	 * Return administration entries by object id: The administrations without their administrator.
	 *
	 * Behavior:
	 * - One entry per object id that EJF has an administration of, keyed by object id in the
	 *   order the ids first occur. An id without an administration has no entry.
	 * - id identifies the administration across its registrations; objectId is null when EJF
	 *   gives none. importSequence is the number of the EJF import batch that brought the
	 *   current registration.
	 * - current is true for status gældende. Ended administrations (historisk) and rolled-back
	 *   registrations (tilbagerullet) are entries too.
	 * - registeredAt is the registration time in UTC, as 2026-11-05T14:09:59Z. validFrom is the
	 *   day the administration took effect, as YYYY-MM-DD, or null for EJF's placeholders for an
	 *   unknown date.
	 * - administeredType is an EjfPartyType value other than production_unit: person when the
	 *   administration has a person, otherwise company when it has a CVR number, other when it
	 *   names a party without CPR or CVR number, and unknown without any of them.
	 *   administeredPerson holds the person's id, CPR status, status date and birth date; for
	 *   status doed, the status date is the date of death. The CVR number and the id of the
	 *   owner information are returned whatever the type.
	 * - Sends the ids in batches of up to 100, one request each. Every request of one call uses
	 *   the same effect time: the time of the call.
	 *
	 * Notes:
	 * - EJF numbers its import batches in one series across its entities, so importSequence
	 *   orders the changes of every administration.
	 *
	 * Typical usage:
	 *   $entries = $this->app->ejf->getAdministrationEntries([627801]);
	 *
	 * @param  array<array-key,int|string>  $objectIds  Object ids as ints or strings of digits without leading zeroes. A repeated id is looked up once.
	 * @return array<int,EjfAdministrationEntry>  Entries keyed by object id.
	 * @throws \InvalidArgumentException  When an object id is not a positive integer without leading zeroes.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a remote lookup fails, or an answer is malformed or holds an administration that was not requested.
	 */
	public function getAdministrationEntries(array $objectIds): array {
		return $this->administrationsByObjectId($objectIds, false);
	}


	/**
	 * Return the administration entries of a range of EJF import batches.
	 *
	 * Gives a host app every administration that is new or changed since a batch it has read:
	 * those whose current registration came with a batch from $fromSequence up to but not
	 * including $toSequence.
	 *
	 * Behavior:
	 * - Lists the entries oldest batch first, then by object id, those without one last, then
	 *   by id. A registration repeated on a later page is kept once.
	 * - Sends one request per page of up to 500 entries. Every request of one call uses the
	 *   same effect time: the time of the call.
	 *
	 * Notes:
	 * - Datafordeler pages in object id order, not in batch order, so a range is read in full
	 *   only when every page of it has been read.
	 *
	 * Typical usage:
	 *   $entries = $this->app->ejf->getImportedAdministrationEntries(454989, 456989);
	 *
	 * @param  int  $fromSequence  First import batch, 0 or more.
	 * @param  int  $toSequence  Import batch after the last one, above $fromSequence and at most 2147483647.
	 * @return list<EjfAdministrationEntry>
	 * @throws \InvalidArgumentException  When the range is empty or reaches outside 0-2147483647.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a remote lookup fails or is malformed.
	 * @see \CitOmni\DanishPublicSectorData\Service\Ejf::getAdministrationEntries()  The fields of an entry.
	 */
	public function getImportedAdministrationEntries(int $fromSequence, int $toSequence): array {
		if ($fromSequence < 0 || $toSequence <= $fromSequence || $toSequence > self::MAX_IMPORT_SEQUENCE) {
			throw new \InvalidArgumentException('Import batch range must start at 0 or more and end above its start, at most ' . self::MAX_IMPORT_SEQUENCE . '.');
		}

		return $this->administrationEntriesWhere(
			'EjfImportedAdministrations',
			'datafordelerRegisterImportSequenceNumber: { gte: ' . $fromSequence . ', lt: ' . $toSequence . ' }'
		);
	}


	/**
	 * Return the administration entries that EJF registered in a period.
	 *
	 * Lets a host app find the import batches of a period before it reads them with
	 * getImportedAdministrationEntries().
	 *
	 * Behavior:
	 * - Lists every administration whose current registration has a registration time from
	 *   $from up to but not including $to, both to the second. A later registration replaces
	 *   an earlier one, so an administration that changed after the period is not listed.
	 * - Lists and pages the entries as getImportedAdministrationEntries() does.
	 *
	 * Typical usage:
	 *   $entries = $this->app->ejf->getRegisteredAdministrationEntries(new \DateTimeImmutable('2026-11-01T00:00:00Z'), new \DateTimeImmutable('2026-11-08T00:00:00Z'));
	 *
	 * @param  \DateTimeInterface  $from  Start of the period, in any timezone.
	 * @param  \DateTimeInterface  $to  End of the period, at least a second after $from.
	 * @return list<EjfAdministrationEntry>
	 * @throws \InvalidArgumentException  When $to is not at least a second after $from.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a remote lookup fails or is malformed.
	 * @see \CitOmni\DanishPublicSectorData\Service\Ejf::getImportedAdministrationEntries()  The order and the paging.
	 */
	public function getRegisteredAdministrationEntries(\DateTimeInterface $from, \DateTimeInterface $to): array {
		$utc = new \DateTimeZone('UTC');
		$start = \DateTimeImmutable::createFromInterface($from)->setTimezone($utc)->format('Y-m-d\\TH:i:s\\Z');
		$end = \DateTimeImmutable::createFromInterface($to)->setTimezone($utc)->format('Y-m-d\\TH:i:s\\Z');

		// Compared as sent: The filter takes whole seconds.
		if ($end <= $start) {
			throw new \InvalidArgumentException('The end of the registration period must be at least a second after its start.');
		}

		// Both operators in one object: Datafordeler fails an and of two filters.
		return $this->administrationEntriesWhere(
			'EjfRegisteredAdministrations',
			'registreringFra: { gte: "' . $start . '", lt: "' . $end . '" }'
		);
	}


	// ----------------------------------------------------------------
	// Requests
	// ----------------------------------------------------------------

	/**
	 * Fetch and normalize the rows of one root field for many BFE numbers.
	 *
	 * Behavior:
	 * - Returns one entry per distinct BFE number in the order the numbers first occur, with the
	 *   rows of the property keyed by row id. A row that comes twice is kept once.
	 *
	 * @param  array<array-key,mixed>  $bfeNumbers  Requested BFE numbers.
	 * @param  string  $root  Root field of the query documents.
	 * @param  \Closure(list<int>,string,?string):string  $query  Builder of the query document for one page; its arguments are at most 100 BFE numbers, the effect time and the cursor of the previous page.
	 * @param  \Closure(array<string,mixed>):(array{id:string,bfeNumber:int}|null)  $normalize  Normalizer of one node; null leaves the node out.
	 * @return array<int,array<string,array<string,mixed>>>  Rows keyed by BFE number and row id.
	 * @throws \InvalidArgumentException  When a BFE number is not a positive integer without leading zeroes.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a remote lookup fails, or an answer is malformed or holds a property that was not requested.
	 */
	private function rowsByBfe(array $bfeNumbers, string $root, \Closure $query, \Closure $normalize): array {
		$requested = self::lookupNumbers($bfeNumbers, 'BFE number');
		if ($requested === []) {
			return [];
		}

		$effectiveAt = \gmdate('Y-m-d\\TH:i:s\\Z');
		$rows = \array_fill_keys($requested, []);

		foreach (\array_chunk($requested, self::BFE_BATCH_SIZE) as $batch) {
			$expected = \array_flip($batch);
			$nodes = $this->connectionNodes(
				$root,
				static fn(?string $after): string => $query($batch, $effectiveAt, $after)
			);

			foreach ($nodes as $node) {
				$row = $normalize($node);
				if ($row === null) {
					continue;
				}
				if (!isset($expected[$row['bfeNumber']])) {
					throw new InvalidResponseException('EJF response contains a row of a property that was not requested.');
				}

				// A row repeated on a later page is the same registration; the first one wins.
				$rows[$row['bfeNumber']][$row['id']] ??= $row;
			}
		}

		return $rows;
	}


	/**
	 * Fetch and normalize the administrations of many object ids.
	 *
	 * @param  array<array-key,mixed>  $objectIds  Requested object ids.
	 * @param  bool  $withAdministrator  True for administrations with their administrator, false for entries.
	 * @return array<int,array<string,mixed>>  Administrations or entries keyed by object id, in the order the ids first occur; an id without an administration has no entry.
	 * @throws \InvalidArgumentException  When an object id is not a positive integer without leading zeroes.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a remote lookup fails, or an answer is malformed or holds an administration that was not requested.
	 */
	private function administrationsByObjectId(array $objectIds, bool $withAdministrator): array {
		$requested = self::lookupNumbers($objectIds, 'Object id');
		if ($requested === []) {
			return [];
		}

		$effectiveAt = \gmdate('Y-m-d\\TH:i:s\\Z');
		$name = $withAdministrator ? 'EjfAdministrations' : 'EjfAdministrationEntries';
		$found = [];

		foreach (\array_chunk($requested, self::OBJECT_ID_BATCH_SIZE) as $batch) {
			$expected = \array_flip($batch);
			$filter = 'objectid: { in: [' . \implode(', ', $batch) . '] }';
			$nodes = $this->connectionNodes(
				'EJFCustom_PersonEllerVirksomhedsadminiBegraenset',
				static fn(?string $after): string => self::administrationsDocument($name, $filter, self::OBJECT_ID_BATCH_SIZE, $effectiveAt, $after, $withAdministrator)
			);

			foreach ($nodes as $node) {
				$row = $withAdministrator ? $this->normalizeAdministration($node) : $this->normalizeAdministrationEntry($node);
				if ($row['objectId'] === null || !isset($expected[$row['objectId']])) {
					throw new InvalidResponseException('EJF response contains an administration that was not requested.');
				}

				// A row repeated on a later page is the same registration; the first one wins.
				$found[$row['objectId']] ??= $row;
			}
		}

		$rows = [];
		foreach ($requested as $objectId) {
			if (isset($found[$objectId])) {
				$rows[$objectId] = $found[$objectId];
			}
		}

		return $rows;
	}


	/**
	 * Fetch and normalize the administration entries that one filter selects.
	 *
	 * @param  string  $name  Operation name of the query documents.
	 * @param  string  $filter  Filter of the where argument, without its braces.
	 * @return list<array<string,mixed>>  Entries oldest import batch first, then by object id, those without one last, then by id; each registration once.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a remote lookup fails or is malformed.
	 */
	private function administrationEntriesWhere(string $name, string $filter): array {
		$effectiveAt = \gmdate('Y-m-d\\TH:i:s\\Z');
		$entries = [];

		$nodes = $this->connectionNodes(
			'EJFCustom_PersonEllerVirksomhedsadminiBegraenset',
			static fn(?string $after): string => self::administrationsDocument($name, $filter, self::ENTRY_PAGE_SIZE, $effectiveAt, $after, false)
		);

		foreach ($nodes as $node) {
			$entry = $this->normalizeAdministrationEntry($node);

			// A row repeated on a later page is the same registration; the first one wins.
			$entries[$entry['id']] ??= $entry;
		}

		$entries = \array_values($entries);
		\usort($entries, static fn(array $a, array $b): int => [$a['importSequence'], $a['objectId'] ?? \PHP_INT_MAX, $a['id']] <=> [$b['importSequence'], $b['objectId'] ?? \PHP_INT_MAX, $b['id']]);

		return $entries;
	}


	/**
	 * Yield the nodes of every page of one root connection.
	 *
	 * Behavior:
	 * - Yields the nodes in response order, one page at a time.
	 * - Requests the next page only when the nodes of the current page have been consumed.
	 *
	 * @param  string  $root  Root field of the query documents.
	 * @param  callable(?string):string  $query  Builder of the query document for one page; its argument is the cursor of the previous page, null for the first.
	 * @return \Generator<int,array<string,mixed>,mixed,void>  Nodes of every page.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\PublicDataException  When a request fails, or a page is malformed or announces a cursor that was already used.
	 */
	private function connectionNodes(string $root, callable $query): \Generator {
		$cursor = null;
		$usedCursors = [];

		do {
			$data = $this->datafordeler->query($this->service, $this->version, $query($cursor));

			$connection = $data[$root] ?? null;
			if (!\is_array($connection) || !\is_array($connection['nodes'] ?? null) || !\is_array($connection['pageInfo'] ?? null)) {
				throw new InvalidResponseException('EJF response does not contain a valid ' . $root . ' connection.');
			}

			$hasNextPage = $connection['pageInfo']['hasNextPage'] ?? null;
			if (!\is_bool($hasNextPage)) {
				throw new InvalidResponseException('EJF response contains invalid ' . $root . ' page information.');
			}

			$cursor = null;
			if ($hasNextPage) {
				$cursor = $connection['pageInfo']['endCursor'] ?? null;

				// A cursor that comes back would request the same pages forever.
				if (!\is_string($cursor) || $cursor === '' || isset($usedCursors[$cursor])) {
					throw new InvalidResponseException('EJF response does not advance the ' . $root . ' pagination.');
				}
				$usedCursors[$cursor] = true;
			}

			foreach ($connection['nodes'] as $node) {
				if (!\is_array($node)) {
					throw new InvalidResponseException('EJF response contains an invalid ' . $root . ' node.');
				}

				yield $node;
			}

			// Release the raw page before the next one is requested.
			unset($data, $connection);
		} while ($cursor !== null);
	}


	/**
	 * Build the query document for one page of the ownerships of properties.
	 *
	 * @param  list<int>  $bfeNumbers  At most 100 BFE numbers.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 */
	private static function ownershipsQuery(array $bfeNumbers, string $effectiveAt, ?string $after): string {
		return self::ownershipsDocument('EjfOwnerships', 'bestemtFastEjendomBFENr: { in: [' . \implode(', ', $bfeNumbers) . '] }', $effectiveAt, $after);
	}


	/**
	 * Build the query document for one page of the ownerships of one company owner.
	 *
	 * @param  int  $cvrNumber  CVR number of eight digits.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 */
	private static function companyOwnershipsQuery(int $cvrNumber, string $effectiveAt, ?string $after): string {
		return self::ownershipsDocument('EjfCompanyOwnerships', 'ejendeVirksomhedCVRNr: { eq: ' . $cvrNumber . ' }', $effectiveAt, $after);
	}


	/**
	 * Build the query document for one page of ownerships with the selection that normalizeOwnership() reads.
	 *
	 * @param  string  $name  Operation name of the document.
	 * @param  string  $filter  Filter of the where argument, without its braces.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 */
	private static function ownershipsDocument(string $name, string $filter, string $effectiveAt, ?string $after): string {
		$first = self::PAGE_SIZE;
		$afterArgument = self::afterArgument($after);
		$person = self::PERSON_SELECTION;

		return <<<GRAPHQL
query {$name} {
	EJFCustom_EjerskabBegraenset(
		first: {$first}{$afterArgument}
		virkningstid: "{$effectiveAt}"
		where: { {$filter} }
	) {
		nodes {
			id_lokalId
			bestemtFastEjendomBFENr
			status
			ejerforholdskode
			faktiskEjerandel_taeller
			faktiskEjerandel_naevner
			tinglystEjerandel_taeller
			tinglystEjerandel_naevner
			ejendeVirksomhedCVRNr
			ejeroplysningerLokalId
			primaerKontakt
			virkningFra
			forretningshaendelse
			forretningsproces
			ejendePersonBegraenset {$person}
			oplysningerEjesAfEjerskab {
				navn
				adresselinje1
				adresselinje2
				adresselinje3
				adresselinje4
				adresselinje5
				adresselinje6
				adresselinje7
				adresselinje8
				adresselinje9
				adresselinje10
				landeKodeNumerisk
			}
		}
		pageInfo {
			hasNextPage
			endCursor
		}
	}
}
GRAPHQL;
	}


	/**
	 * Build the query document for one page of transfers with their trades.
	 *
	 * @param  list<int>  $bfeNumbers  At most 100 BFE numbers.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  GraphQL query document.
	 */
	private static function transfersQuery(array $bfeNumbers, string $effectiveAt, ?string $after): string {
		$first = self::PAGE_SIZE;
		$tradeFirst = self::TRADE_TRANSFER_LIMIT;
		$afterArgument = self::afterArgument($after);
		$numberList = \implode(', ', $bfeNumbers);

		return <<<GRAPHQL
query EjfTransfers {
	EJF_Ejerskifte(
		first: {$first}{$afterArgument}
		virkningstid: "{$effectiveAt}"
		where: { bestemtFastEjendomBFENr: { in: [{$numberList}] } }
	) {
		nodes {
			id_lokalId
			bestemtFastEjendomBFENr
			status
			overdragelsesmaade
			overtagelsesdato
			anmeldelsesdato
			anmeldelsesidentifikator
			betinget
			fristDato
			forretningshaendelse
			forretningsproces
			handelsoplysningerLokalId_23_Handelsoplysninger_id_lokalId_ref {
				id_lokalId
				samletKoebesum
				kontantKoebesum
				loesoeresum
				valutakode
				koebsaftaleDato
				afstaaelsesdato
				betalingsforpligtelsesdato
				bygningerOmfattet
				handelsoplysningerBeskriverEjerskifte(first: {$tradeFirst}) {
					nodes {
						status
						bestemtFastEjendomBFENr
					}
				}
			}
		}
		pageInfo {
			hasNextPage
			endCursor
		}
	}
}
GRAPHQL;
	}


	/**
	 * Build the query document for one page of administrations or administration entries.
	 *
	 * @param  string  $name  Operation name of the document.
	 * @param  string  $filter  Filter of the where argument, without its braces.
	 * @param  int  $first  Rows of the page, 1 to 1000.
	 * @param  string  $effectiveAt  UTC effect time in the form 2026-01-31T12:00:00Z.
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @param  bool  $withAdministrator  True for the selection that normalizeAdministration() reads, false for the one that normalizeAdministrationEntry() reads.
	 * @return string  GraphQL query document.
	 */
	private static function administrationsDocument(string $name, string $filter, int $first, string $effectiveAt, ?string $after, bool $withAdministrator): string {
		$afterArgument = self::afterArgument($after);
		$administrator = $withAdministrator ? self::ADMINISTRATOR_SELECTION . "\n\t\t\tpersonBegraenset " . self::PERSON_SELECTION : '';

		return <<<GRAPHQL
query {$name} {
	EJFCustom_PersonEllerVirksomhedsadminiBegraenset(
		first: {$first}{$afterArgument}
		virkningstid: "{$effectiveAt}"
		where: { {$filter} }
	) {
		nodes {
			id_lokalId
			objectid
			datafordelerRegisterImportSequenceNumber
			status
			registreringFra
			virkningFra
			forretningshaendelse
			forretningsproces
			administreretVirksomCVRNr
			personVirksomOplLokalId
			administreretPersonBegraenset {
				id
				status
				statusdato
				foedselsdato
			}{$administrator}
		}
		pageInfo {
			hasNextPage
			endCursor
		}
	}
}
GRAPHQL;
	}


	/**
	 * Build the after argument of a page request.
	 *
	 * @param  string|null  $after  Cursor of the previous page, or null for the first page.
	 * @return string  Argument line with its leading line break, or an empty string for the first page.
	 */
	private static function afterArgument(?string $after): string {
		if ($after === null) {
			return '';
		}

		// A JSON string literal is also a valid GraphQL string literal.
		return "\n\t\tafter: " . \json_encode($after, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
	}


	/**
	 * Validate BFE numbers or object ids and drop repeats.
	 *
	 * @param  array<array-key,mixed>  $numbers  Requested numbers.
	 * @param  string  $label  Name of the numbers in the exception message, such as BFE number.
	 * @return list<int>  Distinct numbers in the order they first occur.
	 * @throws \InvalidArgumentException  When a number is neither an int nor a string, is not a positive integer without leading zeroes, or exceeds the int range.
	 */
	private static function lookupNumbers(array $numbers, string $label): array {
		$unique = [];
		foreach ($numbers as $number) {
			if (\is_int($number)) {
				$number = (string)$number;
			}
			if (!\is_string($number) || !\preg_match(self::BFE_NUMBER_PATTERN, $number) || \filter_var($number, \FILTER_VALIDATE_INT) === false) {
				throw new \InvalidArgumentException($label . ' must be an int or a string of digits without leading zeroes, at least 1.');
			}

			$unique[(int)$number] = true;
		}

		return \array_keys($unique);
	}


	/**
	 * Order ownerships oldest first by validFrom, those without a known validFrom first, then by id.
	 *
	 * @param  array<array-key,EjfOwnership>  $ownerships
	 * @return list<EjfOwnership>
	 */
	private static function oldestFirst(array $ownerships): array {
		\usort($ownerships, static fn(array $a, array $b): int => [$a['validFrom'] ?? '', $a['id']] <=> [$b['validFrom'] ?? '', $b['id']]);

		return $ownerships;
	}


	// ----------------------------------------------------------------
	// Normalization
	// ----------------------------------------------------------------

	/**
	 * Normalize one EJFCustom_EjerskabBegraenset node.
	 *
	 * @param  array<string,mixed>  $node  Raw ownership node.
	 * @return EjfOwnership
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the node shape or a value is invalid.
	 */
	private function normalizeOwnership(array $node): array {
		$status = self::requiredString($node['status'] ?? null, 'ownership status');
		$person = $this->normalizePerson($node['ejendePersonBegraenset'] ?? null);
		$companyCvr = $node['ejendeVirksomhedCVRNr'] ?? null;
		$companyCvr = $companyCvr !== null ? (string)self::positiveInt($companyCvr, 'owner CVR number') : null;
		$other = self::normalizeOtherOwner($node['oplysningerEjesAfEjerskab'] ?? null);
		$otherId = self::nullableText($node['ejeroplysningerLokalId'] ?? null, 'owner information id');

		$ownerType = match (true) {
			$person !== null => EjfOwnerType::Person,
			$companyCvr !== null => EjfOwnerType::Company,
			$other !== null || $otherId !== null => EjfOwnerType::Other,
			default => EjfOwnerType::Unknown,
		};

		return [
			'id' => self::requiredString($node['id_lokalId'] ?? null, 'ownership id'),
			'bfeNumber' => self::positiveInt($node['bestemtFastEjendomBFENr'] ?? null, 'ownership BFE number'),
			'status' => $status,
			'current' => $status === self::CURRENT_STATUS,
			'validFrom' => $this->instantDate($node['virkningFra'] ?? null, 'ownership effect start', true),
			'ownershipCode' => self::requiredString($node['ejerforholdskode'] ?? null, 'ownership code'),
			'share' => self::share($node['faktiskEjerandel_taeller'] ?? null, $node['faktiskEjerandel_naevner'] ?? null, 'actual share'),
			'registeredShare' => self::share($node['tinglystEjerandel_taeller'] ?? null, $node['tinglystEjerandel_naevner'] ?? null, 'registered share'),
			'primaryContact' => self::requiredBool($node['primaerKontakt'] ?? null, 'primary contact flag'),
			'businessEvent' => self::nullableText($node['forretningshaendelse'] ?? null, 'ownership business event'),
			'businessProcess' => self::nullableText($node['forretningsproces'] ?? null, 'ownership business process'),
			'ownerType' => $ownerType->value,
			'person' => $person,
			'companyCvr' => $companyCvr,
			'other' => $other,
		];
	}


	/**
	 * Normalize the person of an ownership.
	 *
	 * @param  mixed  $person  Raw EJFCustom_PersonSimpelBegraenset object, or null.
	 * @return EjfPerson|null  null when the ownership has no person.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the object shape or a value is invalid.
	 */
	private function normalizePerson(mixed $person): ?array {
		if ($person === null) {
			return null;
		}
		if (!\is_array($person)) {
			throw new InvalidResponseException('EJF response contains an invalid owner person.');
		}

		$name = $person['navn'] ?? null;
		if ($name !== null && !\is_array($name)) {
			throw new InvalidResponseException('EJF response contains an invalid person name.');
		}

		$protections = $person['beskyttelser'] ?? null;
		if (!\is_array($protections)) {
			throw new InvalidResponseException('EJF response contains invalid person protections.');
		}

		$types = [];
		foreach ($protections as $protection) {
			if (!\is_array($protection)) {
				throw new InvalidResponseException('EJF response contains an invalid person protection.');
			}

			$types[self::requiredString($protection['beskyttelsestype'] ?? null, 'protection type')] = true;
		}
		$types = \array_keys($types);
		\sort($types, \SORT_STRING);

		$addressing = $person['adresseoplysninger'] ?? null;
		if ($addressing !== null && !\is_array($addressing)) {
			throw new InvalidResponseException('EJF response contains invalid person addressing data.');
		}

		return [
			'id' => self::requiredString($person['id'] ?? null, 'person id'),
			'status' => self::requiredString($person['status'] ?? null, 'person status'),
			'statusDate' => self::localDate($person['statusdato'] ?? null, 'person status date'),
			'statusDateUncertain' => self::requiredBool($person['statusdatoUsikkerhedsmarkering'] ?? null, 'person status date uncertainty'),
			'birthDate' => self::localDate($person['foedselsdato'] ?? null, 'person birth date'),
			'birthDateUncertain' => self::requiredBool($person['foedselsdatoUsikkerhedsmarkering'] ?? null, 'person birth date uncertainty'),
			'name' => $name === null ? null : self::nullableText($name['navn'] ?? null, 'person name'),
			'protections' => $types,
			'address' => self::normalizeAddress($person['cprAdresse'] ?? null, $person['standardadresse'] ?? null),
			'addressingName' => $addressing === null ? null : self::nullableText($addressing['adresseringsnavn'] ?? null, 'person addressing name'),
			'foreignAddress' => self::normalizeForeignAddress($person['udrejseIndrejse'] ?? null),
		];
	}


	/**
	 * Normalize the CPR address of a person.
	 *
	 * @param  mixed  $address  Raw EJFCustom_PersonSimpelCprAdresse object, or null.
	 * @param  mixed  $line  Raw standardadresse: The street, house number, floor and door in one line.
	 * @return EjfAddress|null  null when neither holds a value.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the object shape or a value is invalid.
	 */
	private static function normalizeAddress(mixed $address, mixed $line): ?array {
		if ($address !== null && !\is_array($address)) {
			throw new InvalidResponseException('EJF response contains an invalid person address.');
		}

		$normalized = [
			'line' => self::nullableText($line, 'person address line'),
			'street' => self::nullableText($address['vejadresseringsnavn'] ?? null, 'address street'),
			'houseNumber' => self::nullableText($address['husnummer'] ?? null, 'address house number'),
			'floor' => self::nullableText($address['etage'] ?? null, 'address floor'),
			'door' => self::nullableText($address['sidedoer'] ?? null, 'address door'),
			'building' => self::nullableText($address['bygningsnummer'] ?? null, 'address building number'),
			'city' => self::nullableText($address['bynavn'] ?? null, 'address city'),
			'postalCode' => self::nullableText($address['postnummer'] ?? null, 'address postcode'),
			'postalDistrict' => self::nullableText($address['postdistrikt'] ?? null, 'address postal district'),
			'municipalityCode' => self::nullableText($address['cprKommunekode'] ?? null, 'address municipality code'),
			'municipalityName' => self::nullableText($address['cprKommunenavn'] ?? null, 'address municipality name'),
			'roadCode' => self::nullableText($address['cprVejkode'] ?? null, 'address road code'),
		];

		// A deceased or emigrated person has an address object without values.
		if (\array_filter($normalized, static fn(?string $value): bool => $value !== null) === []) {
			return null;
		}

		$normalized['formatted'] = self::formatAddress($normalized);

		return $normalized;
	}


	/**
	 * Build the address of a person as one line.
	 *
	 * @param  array<string,?string>  $address  Normalized address without formatted.
	 * @return string|null  Street line, city, and postcode with postal district, separated by commas; null when none is known.
	 */
	private static function formatAddress(array $address): ?string {
		$street = $address['line'];
		if ($street === null && $address['street'] !== null) {
			$street = \trim($address['street'] . ' ' . ($address['houseNumber'] ?? ''));
			$unit = \trim(($address['floor'] ?? '') . ' ' . ($address['door'] ?? ''));
			if ($unit !== '') {
				$street .= ', ' . $unit;
			}
		}

		$postal = \trim(($address['postalCode'] ?? '') . ' ' . ($address['postalDistrict'] ?? ''));
		$parts = \array_filter([$street, $address['city'], $postal], static fn(?string $part): bool => $part !== null && $part !== '');

		return $parts !== [] ? \implode(', ', $parts) : null;
	}


	/**
	 * Normalize the address abroad of an emigrated person.
	 *
	 * @param  mixed  $emigration  Raw EJFCustom_PersonSimpelUdrejseIndrejse object, or null.
	 * @return EjfForeignAddress|null  null when the object holds no address line and no country.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the object shape or a value is invalid.
	 */
	private static function normalizeForeignAddress(mixed $emigration): ?array {
		if ($emigration === null) {
			return null;
		}
		if (!\is_array($emigration)) {
			throw new InvalidResponseException('EJF response contains an invalid foreign address.');
		}

		$lines = [];
		for ($index = 1; $index <= 5; $index++) {
			$line = self::nullableText($emigration['udlandsadresselinje' . $index] ?? null, 'foreign address line');
			if ($line !== null) {
				$lines[] = $line;
			}
		}

		$country = $emigration['cprLandUdrejse'] ?? null;
		if ($country !== null && !\is_array($country)) {
			throw new InvalidResponseException('EJF response contains an invalid emigration country.');
		}

		$countryName = $country === null ? null : self::nullableText($country['navn'] ?? null, 'emigration country name');
		$countryCode = $country === null ? null : self::nullableText($country['landekode'] ?? null, 'emigration country code');

		if ($lines === [] && $countryName === null && $countryCode === null) {
			return null;
		}

		return ['lines' => $lines, 'country' => $countryName, 'countryCode' => $countryCode];
	}


	/**
	 * Normalize the owner information of an owner without CPR or CVR number.
	 *
	 * @param  mixed  $owner  Raw EJF_PersonVirksomhedsoplys object, or null.
	 * @return EjfOtherOwner|null  null when the ownership has no owner information.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the object shape or a value is invalid.
	 */
	private static function normalizeOtherOwner(mixed $owner): ?array {
		if ($owner === null) {
			return null;
		}
		if (!\is_array($owner)) {
			throw new InvalidResponseException('EJF response contains invalid owner information.');
		}

		$lines = [];
		for ($index = 1; $index <= 10; $index++) {
			$line = self::nullableText($owner['adresselinje' . $index] ?? null, 'owner address line');
			if ($line !== null) {
				$lines[] = $line;
			}
		}

		return [
			'name' => self::nullableText($owner['navn'] ?? null, 'owner name'),
			'addressLines' => $lines,
			'countryCode' => self::nullableText($owner['landeKodeNumerisk'] ?? null, 'owner country code'),
		];
	}


	/**
	 * Normalize one EJF_Ejerskifte node, or leave out a rolled-back one.
	 *
	 * @param  array<string,mixed>  $node  Raw transfer node.
	 * @return EjfTransfer|null  null for a transfer whose status is not gældende.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the node shape or a value is invalid.
	 */
	private function normalizeTransfer(array $node): ?array {
		$id = self::requiredString($node['id_lokalId'] ?? null, 'transfer id');
		$bfeNumber = self::positiveInt($node['bestemtFastEjendomBFENr'] ?? null, 'transfer BFE number');

		if (self::requiredString($node['status'] ?? null, 'transfer status') !== self::CURRENT_STATUS) {
			return null;
		}

		$conditional = $node['betinget'] ?? null;

		return [
			'id' => $id,
			'bfeNumber' => $bfeNumber,
			'takeoverDate' => $this->instantDate($node['overtagelsesdato'] ?? null, 'takeover date', true),
			'filedDate' => $this->instantDate($node['anmeldelsesdato'] ?? null, 'filing date', false),
			'method' => self::nullableText($node['overdragelsesmaade'] ?? null, 'transfer method'),
			'businessEvent' => self::nullableText($node['forretningshaendelse'] ?? null, 'transfer business event'),
			'businessProcess' => self::nullableText($node['forretningsproces'] ?? null, 'transfer business process'),
			'conditional' => $conditional === null ? null : self::requiredBool($conditional, 'conditional flag'),
			'deadline' => ($node['fristDato'] ?? null) === null ? null : self::localDate($node['fristDato'], 'transfer deadline'),
			'documentId' => self::nullableText($node['anmeldelsesidentifikator'] ?? null, 'filing identifier'),
			'trade' => self::normalizeTrade($node['handelsoplysningerLokalId_23_Handelsoplysninger_id_lokalId_ref'] ?? null),
		];
	}


	/**
	 * Normalize the trade information of a transfer.
	 *
	 * @param  mixed  $trade  Raw EJF_Handelsoplysninger object, or null.
	 * @return EjfTrade|null  null when the transfer has no trade information.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the object shape or a value is invalid.
	 */
	private static function normalizeTrade(mixed $trade): ?array {
		if ($trade === null) {
			return null;
		}
		if (!\is_array($trade)) {
			throw new InvalidResponseException('EJF response contains invalid trade information.');
		}

		$described = $trade['handelsoplysningerBeskriverEjerskifte'] ?? null;
		if (!\is_array($described) || !\is_array($described['nodes'] ?? null)) {
			throw new InvalidResponseException('EJF response contains invalid transfers of a trade.');
		}

		$bfeNumbers = [];
		foreach ($described['nodes'] as $transfer) {
			if (!\is_array($transfer)) {
				throw new InvalidResponseException('EJF response contains an invalid transfer of a trade.');
			}

			// A rolled-back transfer no longer belongs to the trade.
			if (($transfer['status'] ?? null) === self::CURRENT_STATUS) {
				$bfeNumbers[self::positiveInt($transfer['bestemtFastEjendomBFENr'] ?? null, 'trade BFE number')] = true;
			}
		}
		$bfeNumbers = \array_keys($bfeNumbers);
		\sort($bfeNumbers, \SORT_NUMERIC);

		return [
			'id' => self::requiredString($trade['id_lokalId'] ?? null, 'trade id'),
			'totalPrice' => self::amount($trade['samletKoebesum'] ?? null, 'total price'),
			'cashPrice' => self::amount($trade['kontantKoebesum'] ?? null, 'cash price'),
			'movablesPrice' => self::amount($trade['loesoeresum'] ?? null, 'price of movables'),
			'currency' => self::nullableText($trade['valutakode'] ?? null, 'currency'),
			'contractDate' => ($trade['koebsaftaleDato'] ?? null) === null ? null : self::localDate($trade['koebsaftaleDato'], 'contract date'),
			'disposalDate' => ($trade['afstaaelsesdato'] ?? null) === null ? null : self::localDate($trade['afstaaelsesdato'], 'disposal date'),
			'paymentDate' => ($trade['betalingsforpligtelsesdato'] ?? null) === null ? null : self::localDate($trade['betalingsforpligtelsesdato'], 'payment date'),
			'buildings' => self::nullableText($trade['bygningerOmfattet'] ?? null, 'buildings of the trade'),
			'bfeNumbers' => $bfeNumbers,
		];
	}


	/**
	 * Normalize one EJFCustom_PersonEllerVirksomhedsadminiBegraenset node without its administrator.
	 *
	 * @param  array<string,mixed>  $node  Raw administration node.
	 * @return EjfAdministrationEntry
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the node shape or a value is invalid.
	 */
	private function normalizeAdministrationEntry(array $node): array {
		$status = self::requiredString($node['status'] ?? null, 'administration status');
		$objectId = $node['objectid'] ?? null;
		$person = self::normalizePersonDates($node['administreretPersonBegraenset'] ?? null);
		$cvr = $node['administreretVirksomCVRNr'] ?? null;
		$cvr = $cvr !== null ? (string)self::positiveInt($cvr, 'administered CVR number') : null;
		$otherId = self::nullableText($node['personVirksomOplLokalId'] ?? null, 'administered owner information id');

		$administeredType = match (true) {
			$person !== null => EjfPartyType::Person,
			$cvr !== null => EjfPartyType::Company,
			$otherId !== null => EjfPartyType::Other,
			default => EjfPartyType::Unknown,
		};

		return [
			'id' => self::requiredString($node['id_lokalId'] ?? null, 'administration id'),
			'objectId' => $objectId === null ? null : self::positiveInt($objectId, 'administration object id'),
			'importSequence' => self::positiveInt($node['datafordelerRegisterImportSequenceNumber'] ?? null, 'administration import batch'),
			'status' => $status,
			'current' => $status === self::CURRENT_STATUS,
			'registeredAt' => self::utcInstant($node['registreringFra'] ?? null, 'administration registration time'),
			'validFrom' => $this->instantDate($node['virkningFra'] ?? null, 'administration effect start', true),
			'businessEvent' => self::nullableText($node['forretningshaendelse'] ?? null, 'administration business event'),
			'businessProcess' => self::nullableText($node['forretningsproces'] ?? null, 'administration business process'),
			'administeredType' => $administeredType->value,
			'administeredPerson' => $person,
			'administeredCvr' => $cvr,
			'administeredOtherId' => $otherId,
		];
	}


	/**
	 * Normalize one EJFCustom_PersonEllerVirksomhedsadminiBegraenset node with its administrator.
	 *
	 * @param  array<string,mixed>  $node  Raw administration node.
	 * @return EjfAdministration
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the node shape or a value is invalid.
	 */
	private function normalizeAdministration(array $node): array {
		$person = $this->normalizePerson($node['personBegraenset'] ?? null);
		$cvr = $node['virksomhedCVRNr'] ?? null;
		$cvr = $cvr !== null ? (string)self::positiveInt($cvr, 'administrator CVR number') : null;
		$pNumber = $node['produktionsenhedPNr'] ?? null;
		$pNumber = $pNumber !== null ? (string)self::positiveInt($pNumber, 'administrator P-number') : null;
		$otherId = self::nullableText($node['personVirksomhedOplysLokalId'] ?? null, 'administrator owner information id');
		$other = self::normalizeAdministratorOther($node['personEllerVirksomhedsadministratorErPersonEllerVirksomhedsoplysninger'] ?? null);

		$administratorType = match (true) {
			$person !== null => EjfPartyType::Person,
			$cvr !== null => EjfPartyType::Company,
			$pNumber !== null => EjfPartyType::ProductionUnit,
			$other !== null || $otherId !== null => EjfPartyType::Other,
			default => EjfPartyType::Unknown,
		};

		return $this->normalizeAdministrationEntry($node) + [
			'administratorType' => $administratorType->value,
			'administratorPerson' => $person,
			'administratorCvr' => $cvr,
			'administratorPNumber' => $pNumber,
			'administratorOtherId' => $otherId,
			'administratorOther' => $other,
		];
	}


	/**
	 * Normalize the dates of the administered person of an administration.
	 *
	 * @param  mixed  $person  Raw EJFCustom_PersonSimpelBegraenset object with id, status and dates, or null.
	 * @return EjfPersonDates|null  null when the administration has no person.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the object shape or a value is invalid.
	 */
	private static function normalizePersonDates(mixed $person): ?array {
		if ($person === null) {
			return null;
		}
		if (!\is_array($person)) {
			throw new InvalidResponseException('EJF response contains an invalid administered person.');
		}

		return [
			'id' => self::requiredString($person['id'] ?? null, 'administered person id'),
			'status' => self::requiredString($person['status'] ?? null, 'administered person status'),
			'statusDate' => self::localDate($person['statusdato'] ?? null, 'administered person status date'),
			'birthDate' => self::localDate($person['foedselsdato'] ?? null, 'administered person birth date'),
		];
	}


	/**
	 * Normalize the owner information of an administrator without CPR or CVR number, with its alternative address.
	 *
	 * @param  mixed  $owner  Raw EJF_PersonVirksomhedsoplys object, or null.
	 * @return EjfAdministratorOther|null  null when the administration has no owner information.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the object shape or a value is invalid.
	 */
	private static function normalizeAdministratorOther(mixed $owner): ?array {
		$other = self::normalizeOtherOwner($owner);
		if ($other === null) {
			return null;
		}

		// normalizeOtherOwner() returns null or has checked that the owner is an array.
		$other['alternativeAddress'] = self::normalizeAlternativeAddress($owner['alternativAdresseLokalId_23_AlternativAdresseBegraenset_id_lokalId_ref'] ?? null);

		return $other;
	}


	/**
	 * Normalize the alternative address of an owner without CPR or CVR number.
	 *
	 * @param  mixed  $address  Raw EJFCustom_AlternativAdresseBegraenset object, or null.
	 * @return EjfAlternativeAddress|null  null when the owner has no alternative address.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the object shape or a value is invalid.
	 */
	private static function normalizeAlternativeAddress(mixed $address): ?array {
		if ($address === null) {
			return null;
		}
		if (!\is_array($address)) {
			throw new InvalidResponseException('EJF response contains an invalid alternative address.');
		}

		$status = self::requiredString($address['status'] ?? null, 'alternative address status');
		$lines = [];
		for ($index = 1; $index <= 10; $index++) {
			$line = self::nullableText($address['adresselinje' . $index] ?? null, 'alternative address line');
			if ($line !== null) {
				$lines[] = $line;
			}
		}

		return [
			'status' => $status,
			'current' => $status === self::CURRENT_STATUS,
			'lines' => $lines,
			'countryCode' => self::nullableText($address['landeKodeNumerisk'] ?? null, 'alternative address country code'),
		];
	}


	// ----------------------------------------------------------------
	// Scalar values
	// ----------------------------------------------------------------

	/**
	 * Normalize a required upstream scalar to a non-empty string.
	 *
	 * @param  mixed  $value  Raw upstream value.
	 * @param  string  $label  Safe value label for diagnostics.
	 * @return string  Non-empty string.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the value is missing, empty or not a string.
	 */
	private static function requiredString(mixed $value, string $label): string {
		if (!\is_string($value) || $value === '') {
			throw new InvalidResponseException('EJF response contains a missing or invalid ' . $label . '.');
		}

		return $value;
	}


	/**
	 * Normalize an optional upstream text to trimmed text or null.
	 *
	 * @param  mixed  $value  Raw upstream value.
	 * @param  string  $label  Safe value label for diagnostics.
	 * @return string|null  Trimmed text; null for null or text that is empty after trimming.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the value is neither null nor a string.
	 */
	private static function nullableText(mixed $value, string $label): ?string {
		if ($value === null) {
			return null;
		}
		if (!\is_string($value)) {
			throw new InvalidResponseException('EJF response contains an invalid ' . $label . '.');
		}

		$value = \trim($value);

		return $value !== '' ? $value : null;
	}


	/**
	 * Normalize a required upstream integer of at least 1.
	 *
	 * @param  mixed  $value  Raw upstream value.
	 * @param  string  $label  Safe value label for diagnostics.
	 * @return int  Integer of at least 1.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the value is missing or not an integer of at least 1.
	 */
	private static function positiveInt(mixed $value, string $label): int {
		if (!\is_int($value) || $value < 1) {
			throw new InvalidResponseException('EJF response contains a missing or invalid ' . $label . '.');
		}

		return $value;
	}


	/**
	 * Normalize a required upstream boolean.
	 *
	 * @param  mixed  $value  Raw upstream value.
	 * @param  string  $label  Safe value label for diagnostics.
	 * @return bool
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the value is not a boolean.
	 */
	private static function requiredBool(mixed $value, string $label): bool {
		if (!\is_bool($value)) {
			throw new InvalidResponseException('EJF response contains a missing or invalid ' . $label . '.');
		}

		return $value;
	}


	/**
	 * Normalize an upstream share from its numerator and denominator.
	 *
	 * @param  mixed  $numerator  Raw numerator.
	 * @param  mixed  $denominator  Raw denominator.
	 * @param  string  $label  Safe value label for diagnostics.
	 * @return EjfShare|null  null when both are null.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When only one of them is null, the numerator is below 0 or the denominator below 1.
	 */
	private static function share(mixed $numerator, mixed $denominator, string $label): ?array {
		if ($numerator === null && $denominator === null) {
			return null;
		}
		if (!\is_int($numerator) || !\is_int($denominator) || $numerator < 0 || $denominator < 1) {
			throw new InvalidResponseException('EJF response contains an invalid ' . $label . '.');
		}

		return ['numerator' => $numerator, 'denominator' => $denominator];
	}


	/**
	 * Normalize an optional upstream amount.
	 *
	 * @param  mixed  $value  Raw upstream value.
	 * @param  string  $label  Safe value label for diagnostics.
	 * @return float|null  The amount; null when EJF has none.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the value is neither null nor a finite number.
	 */
	private static function amount(mixed $value, string $label): ?float {
		if ($value === null) {
			return null;
		}
		if ((!\is_int($value) && !\is_float($value)) || !\is_finite((float)$value)) {
			throw new InvalidResponseException('EJF response contains an invalid ' . $label . '.');
		}

		return (float)$value;
	}


	/**
	 * Normalize a required upstream calendar date.
	 *
	 * @param  mixed  $value  Raw upstream value.
	 * @param  string  $label  Safe value label for diagnostics.
	 * @return string  Date that exists, as YYYY-MM-DD.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the value is not a date that exists, written as YYYY-MM-DD.
	 */
	private static function localDate(mixed $value, string $label): string {
		if (
			!\is_string($value)
			|| \preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $match) !== 1
			|| !\checkdate((int)$match[2], (int)$match[3], (int)$match[1])
		) {
			throw new InvalidResponseException('EJF response contains a missing or invalid ' . $label . '.');
		}

		return $value;
	}


	/**
	 * Normalize an upstream instant that stands for a Danish calendar date.
	 *
	 * Behavior:
	 * - Returns the date in Denmark at that instant, as YYYY-MM-DD.
	 * - Returns null for one of EJF's placeholders for an unknown date, and for null when the
	 *   value is optional.
	 *
	 * @param  mixed  $value  Raw upstream value, such as 2006-06-30T22:00:00.000000Z.
	 * @param  string  $label  Safe value label for diagnostics.
	 * @param  bool  $required  Whether null is invalid.
	 * @return string|null
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the value is not an instant with an offset, or null where it is required.
	 */
	private function instantDate(mixed $value, string $label, bool $required): ?string {
		if ($value === null && !$required) {
			return null;
		}
		if (!\is_string($value) || \preg_match(self::INSTANT_PATTERN, $value) !== 1) {
			throw new InvalidResponseException('EJF response contains a missing or invalid ' . $label . '.');
		}

		try {
			$instant = new \DateTimeImmutable($value);
		} catch (\DateMalformedStringException) {
			throw new InvalidResponseException('EJF response contains an invalid ' . $label . '.');
		}

		// Compared in UTC: Before 1894, PHP's Copenhagen time is local mean time, which would move the 1800 placeholder to the day before.
		if (\in_array($instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), self::PLACEHOLDER_INSTANTS, true)) {
			return null;
		}

		return $instant->setTimezone($this->timezone)->format('Y-m-d');
	}


	/**
	 * Normalize a required upstream instant to UTC.
	 *
	 * @param  mixed  $value  Raw upstream value, such as 2026-11-05T14:09:59.000000Z.
	 * @param  string  $label  Safe value label for diagnostics.
	 * @return string  The instant in UTC to the second, such as 2026-11-05T14:09:59Z.
	 * @throws \CitOmni\DanishPublicSectorData\Exception\InvalidResponseException  When the value is not an instant with an offset.
	 */
	private static function utcInstant(mixed $value, string $label): string {
		if (!\is_string($value) || \preg_match(self::INSTANT_PATTERN, $value) !== 1) {
			throw new InvalidResponseException('EJF response contains a missing or invalid ' . $label . '.');
		}

		try {
			$instant = new \DateTimeImmutable($value);
		} catch (\DateMalformedStringException) {
			throw new InvalidResponseException('EJF response contains an invalid ' . $label . '.');
		}

		return $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\\TH:i:s\\Z');
	}


}
