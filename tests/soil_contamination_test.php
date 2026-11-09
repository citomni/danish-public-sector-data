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

/*
 * This provider package intentionally has no package-local Composer vendor tree.
 * The normalization test therefore supplies only the minimal CitOmni base-service
 * contract required to load the real production SoilContamination class.
 */
namespace CitOmni\Kernel\Service {
	abstract class BaseService {
	}
}

namespace {
	use CitOmni\DanishPublicSectorData\Enum\SoilContaminationStatus;
	use CitOmni\DanishPublicSectorData\Exception\InvalidResponseException;
	use CitOmni\DanishPublicSectorData\Service\SoilContamination;
	use CitOmni\DanishPublicSectorData\Support\DkJordWfsClient;

	$root = \dirname(__DIR__);

	require_once $root . '/src/Exception/PublicDataException.php';
	require_once $root . '/src/Exception/InvalidResponseException.php';
	require_once $root . '/src/Enum/SoilContaminationStatus.php';
	require_once $root . '/src/Service/SoilContamination.php';
	require_once $root . '/src/Support/DkJordWfsClient.php';

	$service = (new \ReflectionClass(SoilContamination::class))->newInstanceWithoutConstructor();
	$checks = 0;

	$invoke = static function (string $method, mixed ...$arguments) use ($service): mixed {
		return (new \ReflectionMethod(SoilContamination::class, $method))->invoke($service, ...$arguments);
	};

	$check = static function (bool $condition, string $message) use (&$checks): void {
		if (!$condition) {
			throw new \RuntimeException('FAIL: ' . $message);
		}
		++$checks;
	};

	$throws = static function (string $class, callable $call, string $message) use (&$checks): void {
		try {
			$call();
		} catch (\Throwable $error) {
			if (!$error instanceof $class) {
				throw new \RuntimeException('FAIL: ' . $message . ' (got ' . \get_class($error) . ': ' . $error->getMessage() . ')');
			}
			++$checks;

			return;
		}

		throw new \RuntimeException('FAIL: ' . $message . ' (nothing was thrown)');
	};

	$feature = static fn(string $locationReference, ?string $locationName, int $district, ?string $parcels): array => [
		'type' => 'Feature',
		'properties' => [
			'Lokalitetsnr' => $locationReference,
			'Lokalitetsnavn' => $locationName,
			'Lokalitetsejerlavkode' => $district,
			'Lokalitetsmatrikler' => $parcels,
		],
	];

	// The same locality arrives in several features with the same list, and 4bz repeats inside it.
	$main = '311a,V2 kortlagt;311b,V2 kortlagt;301a,V1 kortlagt;4ca,V1 og V2 kortlagt;4bz,V1 og V2 kortlagt;4bz,V1 og V2 kortlagt;312,V2 kortlagt';
	$features = [
		$feature('851-00047', 'Nedlagt svovlsyre og gødningsfabrik, Limfjordsvej', 2005352, $main),
		$feature('851-00047', 'Nedlagt svovlsyre og gødningsfabrik, Limfjordsvej', 2005352, $main),
		$feature('851-00470', null, 2005352, '7000e,Udgået efter kortlægning;4bz,V1 og V2 kortlagt'),
		$feature('851-01234', 'Tidligere tankstation', 2005352, ' 1ee , Lokaliseret (uafklaret) ;; 2e,Udgået inden kortlægning;'),
	];

	$parcels = $invoke('parseLocalities', 2005352, $features);
	$status = static fn(string $parcel): array => $invoke('normalizeParcelStatus', 2005352, $parcel, $parcels);

	$parcel = $status('311a');
	$check($parcel['cadastralDistrictIdentifier'] === 2005352, 'Cadastral district is preserved.');
	$check($parcel['landParcelIdentifier'] === '311a', 'Exact land parcel identifier is preserved.');
	$check($parcel['hasDkJordMatch'] === true, 'A listed parcel is reported as a DKjord match.');
	$check($parcel['status'] === SoilContaminationStatus::V2->value, 'V2 kortlagt becomes the v2 status.');
	$check($parcel['statusText'] === 'V2 kortlagt', 'DKjord status text is preserved.');
	$check(
		$parcel['locations'] === [['locationReference' => '851-00047', 'locationName' => 'Nedlagt svovlsyre og gødningsfabrik, Limfjordsvej']],
		'Repeated features of one locality collapse into one location.'
	);

	$parcel = $status('4bz');
	$check($parcel['status'] === SoilContaminationStatus::V1AndV2->value, 'V1 og V2 kortlagt becomes one v1_v2 status, not two.');
	$check(
		\array_column($parcel['locations'], 'locationReference') === ['851-00047', '851-00470'],
		'A parcel in several localities lists each locality once, ordered by number.'
	);
	$check($parcel['locations'][1]['locationName'] === null, 'A locality without a name keeps a null name.');

	$check($status('301a')['status'] === SoilContaminationStatus::V1->value, 'V1 kortlagt becomes the v1 status.');
	$check($status('7000e')['status'] === SoilContaminationStatus::RemovedAfterMapping->value, 'Udgået efter kortlægning becomes removed_after_mapping.');
	$check($status('2e')['status'] === SoilContaminationStatus::RemovedBeforeMapping->value, 'Udgået inden kortlægning becomes removed_before_mapping.');
	$check($status('1ee')['status'] === SoilContaminationStatus::Localized->value, 'Entries are trimmed and empty entries are skipped.');
	$check($status('312')['status'] === SoilContaminationStatus::V2->value, 'A numeric matrikelnummer is matched as well.');

	$parcel = $status('311');
	$check($parcel['hasDkJordMatch'] === false, 'Parcel matching does not use substring semantics.');
	$check($parcel['status'] === null && $parcel['statusText'] === null, 'An unlisted parcel has no status.');
	$check($parcel['locations'] === [], 'An unlisted parcel has no locations.');

	$conflicting = $invoke('parseLocalities', 2005352, [
		$feature('851-00001', null, 2005352, '10a,V1 kortlagt;10b,V2 kortlagt'),
		$feature('851-00002', null, 2005352, '10a,V2 kortlagt'),
	]);
	$throws(
		InvalidResponseException::class,
		static fn() => $invoke('normalizeParcelStatus', 2005352, '10a', $conflicting),
		'A parcel with two statuses is rejected instead of guessed.'
	);
	$check(
		$invoke('normalizeParcelStatus', 2005352, '10b', $conflicting)['status'] === SoilContaminationStatus::V2->value,
		'A conflict on one parcel does not affect the other parcels of the ejerlav.'
	);

	$unknown = $invoke('parseLocalities', 2005352, [$feature('851-00003', null, 2005352, '11a,V3 kortlagt')]);
	$throws(
		InvalidResponseException::class,
		static fn() => $invoke('normalizeParcelStatus', 2005352, '11a', $unknown),
		'An unknown status text is rejected instead of guessed.'
	);

	foreach (['311a', ',V2 kortlagt', '311a,', ' , '] as $entry) {
		$throws(
			InvalidResponseException::class,
			static fn() => $invoke('parseLocalities', 2005352, [$feature('851-00004', null, 2005352, $entry)]),
			'A malformed Lokalitetsmatrikler entry is rejected: "' . $entry . '".'
		);
	}
	$throws(
		InvalidResponseException::class,
		static fn() => $invoke('parseLocalities', 2005352, [$feature('851-00005', null, 2005353, '1a,V1 kortlagt')]),
		'A locality outside the requested ejerlav is rejected.'
	);
	$throws(
		InvalidResponseException::class,
		static fn() => $invoke('parseLocalities', 2005352, [['type' => 'Feature']]),
		'A feature without properties is rejected.'
	);
	$check(
		$invoke('parseLocalities', 2005352, [$feature('851-00006', null, 2005352, null)]) === [],
		'A locality without parcels adds nothing.'
	);

	$statusTexts = (new \ReflectionClass(SoilContamination::class))->getConstant('DKJORD_STATUS_TEXTS');
	foreach (['V1 kortlagt', 'V2 kortlagt', 'V1 og V2 kortlagt', 'Lokaliseret (uafklaret)', 'Udgået efter kortlægning', 'Udgået inden kortlægning'] as $text) {
		$check(isset($statusTexts[$text]), 'Status text seen in DKjord WFS data is known: ' . $text . '.');
	}
	$check(
		\count(\array_unique(\array_map(static fn(SoilContaminationStatus $value): string => $value->value, $statusTexts))) === \count(SoilContaminationStatus::cases()),
		'Every SoilContaminationStatus case has at least one DKjord status text.'
	);

	$check(
		$invoke('orderedStatuses', ['removed_after_mapping' => true, 'v1_v2' => true, 'v1' => true]) === ['v1', 'v1_v2', 'removed_after_mapping'],
		'Property statuses follow SoilContaminationStatus case order.'
	);

	$check($invoke('splitSemicolonList', ' 4bz ; 301a;; ') === ['4bz', '301a'], 'Semicolon lists are trimmed without fuzzy matching.');
	$check($invoke('splitSemicolonList', null) === [], 'Null semicolon lists normalize to an empty list.');

	$check($invoke('isCurrentStatus', 'GÆLDENDE') === true, 'Uppercase current Matriklen status is accepted.');
	$check($invoke('isCurrentStatus', 'Gældende') === true, 'Mixed-case current Matriklen status is accepted.');
	$check($invoke('isCurrentStatus', 'Foreløbig') === false, 'Non-current Matriklen status is rejected.');

	// A pending cadastral case adds a preliminary version of the property next to the current one.
	$current = ['id_lokalId' => 'sfe-1', 'status' => 'Gældende'];
	$preliminary = ['id_lokalId' => 'sfe-1', 'status' => 'Foreløbig'];
	$check($invoke('currentNode', 'MAT_SamletFastEjendom', 'BFE 1', []) === null, 'A lookup without nodes has no current node.');
	$check($invoke('currentNode', 'MAT_SamletFastEjendom', 'BFE 1', [$current]) === $current, 'One current node is returned.');
	$check($invoke('currentNode', 'MAT_SamletFastEjendom', 'BFE 1', [$preliminary, $current]) === $current, 'A preliminary version next to the current one is ignored.');
	$check($invoke('currentNode', 'MAT_SamletFastEjendom', 'BFE 1', [$preliminary]) === null, 'A preliminary version alone is no current node.');
	$check($invoke('currentNode', 'MAT_SamletFastEjendom', 'BFE 1', [$current, $current]) === $current, 'Identical current nodes count once.');
	$check($invoke('currentNode', 'MAT_SamletFastEjendom', 'BFE 1', [['id_lokalId' => 'sfe-2']]) === ['id_lokalId' => 'sfe-2'], 'A node without status counts as current.');
	$throws(
		InvalidResponseException::class,
		static fn() => $invoke('currentNode', 'MAT_SamletFastEjendom', 'BFE 1', [$current, ['id_lokalId' => 'sfe-2', 'status' => 'Gældende']]),
		'Two different current properties for one BFE stay ambiguous.'
	);
	try {
		$invoke('currentNode', 'MAT_SamletFastEjendom', 'BFE 1', [$current, $preliminary, ['id_lokalId' => 'sfe-1', 'status' => 'Gældende', 'samletFastEjendomLokalId' => 'x']]);
	} catch (InvalidResponseException $error) {
		$check(
			$error->getMessage() === 'Datafordeler returned 2 current MAT_SamletFastEjendom nodes for BFE 1 (statuses of all nodes: Gældende, Foreløbig, Gældende).',
			'The ambiguity message names the lookup and the statuses of all nodes.'
		);
	}

	$soilReflection = new \ReflectionClass(SoilContamination::class);
	$parcelScopeMethod = $soilReflection->getMethod('getParcelsByBfe');
	$check($parcelScopeMethod->isPublic(), 'Pure BFE-to-parcel resolution is exposed without requiring DKjord WFS.');
	$check(
		$soilReflection->getConstant('DKJORD_LOCALITY_PROPERTIES') === ['Lokalitetsnr', 'Lokalitetsnavn', 'Lokalitetsejerlavkode', 'Lokalitetsmatrikler'],
		'The locality request names only the properties the lookup reads.'
	);

	$buildPropertyNames = (new \ReflectionClass(DkJordWfsClient::class))->getMethod('buildPropertyNames');
	$check(
		$buildPropertyNames->invoke(null, ['Lokalitetsnr', 'Lokalitetsmatrikler']) === 'Lokalitetsnr,Lokalitetsmatrikler',
		'WFS projection joins the requested property names in order.'
	);
	foreach ([[], ['Lokalitetsnr,GUID'], ['Lokalitets nr'], [42]] as $names) {
		$throws(
			\InvalidArgumentException::class,
			static fn() => $buildPropertyNames->invoke(null, $names),
			'WFS projection rejects an empty list or an invalid property name.'
		);
	}

	$query = $invoke(
		'buildSfeByBfeQuery',
		4268969,
		'2026-09-30T10:00:00Z',
		null
	);
	$check(\str_contains($query, 'MAT_SamletFastEjendom('), 'BFE lookup targets Matriklen SFE.');
	$check(\str_contains($query, 'BFEnummer: { eq: 4268969 }'), 'BFE lookup is exact.');
	$check(\str_contains($query, 'virkningstid: "2026-09-30T10:00:00Z"'), 'BFE lookup carries an explicit effect time.');
	foreach (['buildSfeByBfeQuery', 'buildBpfgFladeByBfeQuery', 'buildBpfgPunktByBfeQuery', 'buildOwnerApartmentByBfeQuery'] as $builder) {
		$check(\preg_match('/nodes \{\s+id_lokalId\s+status\s/', $invoke($builder, 4268969, '2026-09-30T10:00:00Z', null)) === 1, $builder . ' selects the status of each node.');
	}
	foreach (['buildBpfgFladeByIdQuery', 'buildBpfgPunktByIdQuery'] as $builder) {
		$check(\preg_match('/nodes \{\s+id_lokalId\s+status\s/', $invoke($builder, 'id-1', '2026-09-30T10:00:00Z', null)) === 1, $builder . ' selects the status of each node.');
	}

	$query = $invoke(
		'buildParcelsBySfeQuery',
		'11111111-1111-1111-1111-111111111111',
		'2026-09-30T10:00:00Z',
		null
	);
	$check(\str_contains($query, 'MAT_Jordstykke('), 'Parcel lookup targets Matriklen parcels.');
	$check(\str_contains($query, 'first: 1000'), 'Parcel lookup uses the configured page size.');
	$check(\str_contains($query, 'samletFastEjendomLokalId: { eq: "11111111-1111-1111-1111-111111111111" }'), 'Parcel lookup scopes by exact SFE id.');

	\fwrite(
		STDOUT,
		'PASS ' . $checks . ' checks. DKjord locality status parsing and Matriklen query contract. PHP '
		. \PHP_VERSION . '.' . \PHP_EOL
	);
}
