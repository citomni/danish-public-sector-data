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

namespace CitOmni\DanishPublicSectorData\Util;

/**
 * BbrFieldCatalog: Preserve normalized BBR fields not promoted to dedicated public keys.
 *
 * Behavior:
 * - Describes BBR v3 domain fields that are queried but not mapped to dedicated normalized keys.
 * - Emits only non-null and non-empty values while preserving zero and false values.
 * - Adds bundled code-list labels when a field has a known BBR code list.
 * - Keeps upstream field identifiers as source metadata without exposing raw GraphQL payloads.
 *
 * Notes:
 * - This catalog is static runtime data generated from the bundled BBR v3 schema.
 * - GraphQL schema parsing never occurs during normal application requests.
 * - Dedicated normalized fields remain authoritative when a field is intentionally promoted.
 */
final class BbrFieldCatalog {

	/** @var array<string,array<string,array{label:string,type:string,codeList:?string}>> */
	private const array ADDITIONAL_FIELDS = [
		'ground' => [
			'gru025TilladelseTilUdtraeden' => ['label' => 'Tilladelse til udtræden', 'type' => 'code', 'codeList' => 'TilladelseTilUdtraeden'],
			'gru026DatoForTilladelseTilUdtraeden' => ['label' => 'Dato for tilladelse til udtræden', 'type' => 'datetime', 'codeList' => null],
			'gru027TilladelseTilAlternativBortskaffelseEllerAfledning' => ['label' => 'Tilladelse til alt bortskaffelse eller afledning', 'type' => 'code', 'codeList' => 'TilladelseTilAlternativBortskaffelseEllerAfledning'],
			'gru028DatoForTilladelseTilAlternativBortskaffelseEllerAfledning' => ['label' => 'Dato for tilladelse til alt bortskaffelse eller afledning', 'type' => 'datetime', 'codeList' => null],
			'gru029DispensationFritagelseIftKollektivVarmeforsyning' => ['label' => 'Dispensation fritagelse ift. kollektiv varmeforsyning', 'type' => 'code', 'codeList' => 'DispensationFritagelseIftKollektivVarmeforsyning'],
			'gru030DatoForDispensationFritagelseIftKollektivVarmeforsyning' => ['label' => 'Dato for dispensation fritagelse ift. kollektiv varmeforsyning', 'type' => 'datetime', 'codeList' => null],
			'gru500Notatlinjer' => ['label' => 'Notatlinjer', 'type' => 'string', 'codeList' => null],
		],
		'building' => [
			'byg029DatoForMidlertidigOpfoertBygning' => ['label' => 'Dato for midlertidig opført bygning', 'type' => 'datetime', 'codeList' => null],
			'byg046SamletArealAfLukkedeOverdaekningerPaaBygningen' => ['label' => 'Samlet areal af lukkede overdækninger på bygningen', 'type' => 'integer', 'codeList' => null],
			'byg047ArealAfAffaldsrumITerraenniveau' => ['label' => 'Areal af indbygget affaldsrum i terræn', 'type' => 'integer', 'codeList' => null],
			'byg048AndetAreal' => ['label' => 'Øvrige arealer', 'type' => 'integer', 'codeList' => null],
			'byg050ArealAabneOverdaekningerPaaBygningenSamlet' => ['label' => 'Areal åbne overdækninger på bygningen samlet', 'type' => 'integer', 'codeList' => null],
			'byg051Adgangsareal' => ['label' => 'Bygningens adgangsareal', 'type' => 'integer', 'codeList' => null],
			'byg052BeregningsprincipCarportAreal' => ['label' => 'Beregningsprincip carport areal', 'type' => 'code', 'codeList' => 'BeregningsprincipForArealAfCarport'],
			'byg055AfvigendeEtager' => ['label' => 'Afvigende etager', 'type' => 'code', 'codeList' => 'AfvigendeEtager'],
			'byg069Sikringsrumpladser' => ['label' => 'Sikringsrumpladser', 'type' => 'integer', 'codeList' => null],
			'byg094Revisionsdato' => ['label' => 'Revisionsdato', 'type' => 'datetime', 'codeList' => null],
			'byg111StormraadetsOversvoemmelsesSelvrisiko' => ['label' => 'Erstatning for skader efter stormflod og oversvømmelser, jf. Naturskaderådet', 'type' => 'code', 'codeList' => 'Oversvoemmelsesselvrisiko'],
			'byg112DatoForRegistreringFraStormraadet' => ['label' => 'Dato for registrering fra Naturskaderådet', 'type' => 'datetime', 'codeList' => null],
			'byg113Byggeskadeforsikringsselskab' => ['label' => 'Byggeskadeforsikringsselskab', 'type' => 'code', 'codeList' => 'Byggeskadeforsikringsselskab'],
			'byg114DatoForByggeskadeforsikring' => ['label' => 'Dato for byggeskadeforsikring', 'type' => 'datetime', 'codeList' => null],
			'byg121OmfattetAfByggeskadeforsikring' => ['label' => 'Omfattet af byggeskadeforsikring', 'type' => 'code', 'codeList' => 'OmfattetAfByggeskadeforsikring'],
			'byg122Gyldighedsdato' => ['label' => 'Gyldighedsdato', 'type' => 'datetime', 'codeList' => null],
			'byg126TilladelseTilUdtraeden' => ['label' => 'Tilladelse til udtræden', 'type' => 'code', 'codeList' => 'TilladelseTilUdtraeden'],
			'byg127DatoForTilladelseTilUdtraeden' => ['label' => 'Dato for tilladelse til udtræden', 'type' => 'datetime', 'codeList' => null],
			'byg128TilladelseTilAlternativBortskaffelseEllerAfledning' => ['label' => 'Tilladelse til alt bortskaffelse eller afledning', 'type' => 'code', 'codeList' => 'TilladelseTilAlternativBortskaffelseEllerAfledning'],
			'byg129DatoForTilladelseTilAlternativBortskaffelseEllerAfledning' => ['label' => 'Dato for meddelelse af tilladelse eller dato for tilladelsens ophør til alternativ bortskaffelse eller afledning', 'type' => 'datetime', 'codeList' => null],
			'byg130ArealAfUdvendigEfterisolering' => ['label' => 'Areal af udvendig efterisolering', 'type' => 'integer', 'codeList' => null],
			'byg131DispensationFritagelseIftKollektivVarmeforsyning' => ['label' => 'Dispensation fritagelse ift. kollektiv varmeforsyning', 'type' => 'code', 'codeList' => 'DispensationFritagelseIftKollektivVarmeforsyning'],
			'byg132DatoForDispensationFritagelseIftKollektivVarmeforsyning' => ['label' => 'Dato for dispensation fritagelse ift. kollektiv varmeforsyning', 'type' => 'datetime', 'codeList' => null],
			'byg133KildeTilKoordinatsaet' => ['label' => 'Kilde til koordinatsæt', 'type' => 'code', 'codeList' => 'KildeTilKoordinatsaet'],
			'byg134KvalitetAfKoordinatsaet' => ['label' => 'Kvalitet af koordinatsæt', 'type' => 'code', 'codeList' => 'KvalitetAfKoordinatsaet'],
			'byg135SupplerendeOplysningOmKoordinatsaet' => ['label' => 'Supplerende oplysning om koordinatsæt', 'type' => 'code', 'codeList' => 'SupplerendeOplysningerOmKoordinatsaet'],
			'byg136PlaceringPaaSoeterritorie' => ['label' => 'Placering på søterritorie', 'type' => 'code', 'codeList' => 'PaaSoeTerritorie'],
			'byg137BanedanmarkBygvaerksnummer' => ['label' => 'Banedanmark bygværksnummer', 'type' => 'string', 'codeList' => null],
			'byg140ServitutForUdlejningsEjendomDato' => ['label' => 'Servitut for udlejningsejendom dato', 'type' => 'datetime', 'codeList' => null],
			'byg150Gulvbelaegning' => ['label' => 'Gulvbelægning', 'type' => 'code', 'codeList' => 'Gulvbelaegning'],
			'byg151Frihoejde' => ['label' => 'Frihøjde', 'type' => 'float', 'codeList' => null],
			'byg152AabenLukketKonstruktion' => ['label' => 'Åben lukket konstruktion', 'type' => 'code', 'codeList' => 'Konstruktion'],
			'byg153Konstruktionsforhold' => ['label' => 'Konstruktionsforhold', 'type' => 'code', 'codeList' => 'Konstruktionsforhold'],
			'byg301TypeAfFlytning' => ['label' => 'Type af flytning', 'type' => 'string', 'codeList' => null],
			'byg302Tilflytterkommune' => ['label' => 'Tilflytterkommune', 'type' => 'integer', 'codeList' => null],
			'byg403OevrigeBemaerkningerFraStormraadet' => ['label' => 'Øvrige bemærkninger fra Naturskaderådet', 'type' => 'string', 'codeList' => null],
			'byg404Koordinat' => ['label' => 'Koordinatsæt', 'type' => 'coordinate', 'codeList' => null],
			'byg406Koordinatsystem' => ['label' => 'Referencesystem', 'type' => 'code', 'codeList' => 'Koordinatsystem'],
		],
		'unit' => [
			'enh008UUIDTilModerlejlighed' => ['label' => 'UUID til moderlejlighed', 'type' => 'string', 'codeList' => null],
			'enh024KondemneretBoligenhed' => ['label' => 'Kondemneret boligenhed', 'type' => 'code', 'codeList' => 'KondemneretBoligenhed'],
			'enh025OprettelsesdatoForEnhedensIdentifikation' => ['label' => 'Oprettelsesdato for enhedens identifikation', 'type' => 'datetime', 'codeList' => null],
			'enh030KildeTilEnhedensArealer' => ['label' => 'Kilde til enhedens arealer', 'type' => 'code', 'codeList' => 'KildeTilOplysninger'],
			'enh039AndetAreal' => ['label' => 'Tinglyst areal tilknyttet enheden', 'type' => 'integer', 'codeList' => null],
			'enh041LovligAnvendelse' => ['label' => 'Lovlig helårsanvendelse', 'type' => 'code', 'codeList' => 'LovligAnvendelse'],
			'enh042DatoForTidsbegraensetDispensation' => ['label' => 'Dato for tidsbegrænset dispensation', 'type' => 'datetime', 'codeList' => null],
			'enh044DatoForDelvisIbrugtagningsTilladelse' => ['label' => 'Dato for delvis ibrugtagningstilladelse', 'type' => 'datetime', 'codeList' => null],
			'enh046OffentligStoette' => ['label' => 'Offentlig støtte', 'type' => 'code', 'codeList' => 'OffentligStoette'],
			'enh047IndflytningDato' => ['label' => 'Dato for indflytning', 'type' => 'datetime', 'codeList' => null],
			'enh048GodkendtTomBolig' => ['label' => 'Godkendt tom bolig', 'type' => 'code', 'codeList' => 'GodkendtTomBolig'],
			'enh060EnhedensAndelFaellesAdgangsareal' => ['label' => 'Enhedens andel fælles adgangsareal', 'type' => 'integer', 'codeList' => null],
			'enh061ArealAfAabenOverdaekning' => ['label' => 'Areal af åben overdækning', 'type' => 'integer', 'codeList' => null],
			'enh062ArealAfLukketOverdaekningUdestue' => ['label' => 'Areal af lukket altan udestue', 'type' => 'integer', 'codeList' => null],
			'enh063AntalVaerelserTilErhverv' => ['label' => 'Antal værelser til erhverv', 'type' => 'integer', 'codeList' => null],
			'enh067Stoejisolering' => ['label' => 'Støjisolering', 'type' => 'integer', 'codeList' => null],
			'enh068FlexboligTilladelsesart' => ['label' => 'Flexbolig tilladelsesart', 'type' => 'code', 'codeList' => 'Tilladelsesart'],
			'enh069FlexboligOphoersdato' => ['label' => 'Flexbolig ophørsdato', 'type' => 'datetime', 'codeList' => null],
			'enh070AabenAltanTagterrasseAreal' => ['label' => 'Åben altan tagterrasse areal', 'type' => 'integer', 'codeList' => null],
			'enh071AdresseFunktion' => ['label' => 'Adresse funktion', 'type' => 'code', 'codeList' => 'AdresseRolle'],
			'enh101Gyldighedsdato' => ['label' => 'Gyldighedsdato', 'type' => 'datetime', 'codeList' => null],
			'enh102HerafAreal1' => ['label' => 'Heraf areal 1', 'type' => 'integer', 'codeList' => null],
			'enh103HerafAreal2' => ['label' => 'Heraf areal 2', 'type' => 'integer', 'codeList' => null],
			'enh104HerafAreal3' => ['label' => 'Heraf areal 3', 'type' => 'integer', 'codeList' => null],
			'enh105SupplerendeAnvendelseskode1' => ['label' => 'Supplerende anvendelseskode 1', 'type' => 'code', 'codeList' => 'EnhAnvendelse'],
			'enh106SupplerendeAnvendelseskode2' => ['label' => 'Supplerende anvendelseskode 2', 'type' => 'code', 'codeList' => 'EnhAnvendelse'],
			'enh107SupplerendeAnvendelseskode3' => ['label' => 'Supplerende anvendelseskode 3', 'type' => 'code', 'codeList' => 'EnhAnvendelse'],
			'enh127FysiskArealTilBeboelse' => ['label' => 'Fysisk areal til beboelse', 'type' => 'integer', 'codeList' => null],
			'enh128FysiskArealTilErhverv' => ['label' => 'Fysisk areal til erhverv', 'type' => 'integer', 'codeList' => null],
			'enh500Notatlinjer' => ['label' => 'Notatlinjer', 'type' => 'string', 'codeList' => null],
		],
		'floor' => [
			'eta024EtagensAdgangsareal' => ['label' => 'Etagens adgangsareal', 'type' => 'integer', 'codeList' => null],
			'eta026ErhvervIKaelder' => ['label' => 'Areal af erhverv i kælder', 'type' => 'integer', 'codeList' => null],
			'eta500Notatlinjer' => ['label' => 'Notatlinjer', 'type' => 'string', 'codeList' => null],
		],
		'entrance' => [
			'opg500Notatlinjer' => ['label' => 'Notatlinjer', 'type' => 'string', 'codeList' => null],
		],
		'technicalInstallation' => [
			'tek022EksternDatabase' => ['label' => 'Ekstern database', 'type' => 'string', 'codeList' => null],
			'tek023EksternNoegle' => ['label' => 'Ekstern nøgle', 'type' => 'string', 'codeList' => null],
			'tek037Areal' => ['label' => 'Areal', 'type' => 'integer', 'codeList' => null],
			'tek038Hoejde' => ['label' => 'Højde', 'type' => 'integer', 'codeList' => null],
			'tek039Effekt' => ['label' => 'Effekt', 'type' => 'integer', 'codeList' => null],
			'tek040Fredning' => ['label' => 'Fredning', 'type' => 'code', 'codeList' => 'Fredning'],
			'tek042Revisionsdato' => ['label' => 'Revisionsdato', 'type' => 'datetime', 'codeList' => null],
			'tek045Koordinatsystem' => ['label' => 'Referencesystem', 'type' => 'code', 'codeList' => 'Koordinatsystem'],
			'tek069SupplerendeIndvendigKorrosionsbeskyttelse' => ['label' => 'Supplerende indvendig korrosionsbeskyttelse', 'type' => 'code', 'codeList' => 'SupplerendeIndvendigKorrosionsbeskyttelse'],
			'tek070DatoForSenestUdfoerteSupplerendeIndvendigKorrosionsbeskyttelse' => ['label' => 'Dato for senest udførte supplerende indvendig korrosionsbeskyttelse', 'type' => 'datetime', 'codeList' => null],
			'tek073Navhoejde' => ['label' => 'Navhøjde', 'type' => 'float', 'codeList' => null],
			'tek074Vindmoellenummer' => ['label' => 'Vindmøllenummer', 'type' => 'integer', 'codeList' => null],
			'tek075Rotordiameter' => ['label' => 'Rotordiameter', 'type' => 'float', 'codeList' => null],
			'tek076KildeTilKoordinatsaet' => ['label' => 'Kilde til koordinatsæt', 'type' => 'code', 'codeList' => 'KildeTilKoordinatsaet'],
			'tek077KvalitetAfKoordinatsaet' => ['label' => 'Kvalitet af koordinatsæt', 'type' => 'code', 'codeList' => 'KvalitetAfKoordinatsaet'],
			'tek078SupplerendeOplysningOmKoordinatsaet' => ['label' => 'Supplerende oplysning om koordinatsæt', 'type' => 'code', 'codeList' => 'SupplerendeOplysningerOmKoordinatsaet'],
			'tek101Gyldighedsdato' => ['label' => 'Gyldighedsdato', 'type' => 'datetime', 'codeList' => null],
			'tek102FabrikatVindmoelle' => ['label' => 'Fabrikat vindmølle', 'type' => 'string', 'codeList' => null],
			'tek103FabrikatOliefyr' => ['label' => 'Fabrikat oliefyr', 'type' => 'string', 'codeList' => null],
			'tek104FabrikatSolcelleanlaegSolvarme' => ['label' => 'Fabrikat solcelleanlæg solvarme', 'type' => 'string', 'codeList' => null],
			'tek107PlaceringPaaSoeterritorie' => ['label' => 'Placering på søterritorie', 'type' => 'code', 'codeList' => 'PaaSoeTerritorie'],
			'tek112InspicerendeVirksomhed' => ['label' => 'Inspicerende virksomhed', 'type' => 'string', 'codeList' => null],
			'tek500Notatlinjer' => ['label' => 'Notatlinjer', 'type' => 'string', 'codeList' => null],
		],
	];

	/**
	 * Normalize additional fields for one BBR entity.
	 *
	 * @param string $entity Catalog entity key.
	 * @param array<string,mixed> $node Raw GraphQL node.
	 * @return list<array{sourceField:string,label:string,type:string,value:mixed,valueLabel:?string}> Additional non-empty fields.
	 */
	public static function normalizeAdditionalFields(string $entity, array $node): array {
		$catalog = self::ADDITIONAL_FIELDS[$entity] ?? null;
		if ($catalog === null) {
			throw new \LogicException('Unknown BBR field-catalog entity.');
		}

		$result = [];

		foreach ($catalog as $sourceField => $meta) {
			if (!\array_key_exists($sourceField, $node)) {
				continue;
			}

			$value = $node[$sourceField];
			if ($value === null || $value === '') {
				continue;
			}

			if ($meta['type'] === 'coordinate') {
				if (!\is_array($value)) {
					continue;
				}

				$value = [
					'crs' => isset($value['crs']) ? (int)$value['crs'] : null,
					'wkt' => isset($value['wkt']) && $value['wkt'] !== '' ? (string)$value['wkt'] : null,
				];

				if ($value['crs'] === null && $value['wkt'] === null) {
					continue;
				}
			}

			$valueLabel = null;
			if ($meta['codeList'] !== null && !\is_array($value)) {
				$valueLabel = BbrCodeLists::label($meta['codeList'], (string)$value);
			}

			$result[] = [
				'sourceField' => $sourceField,
				'label' => $meta['label'],
				'type' => $meta['type'],
				'value' => $value,
				'valueLabel' => $valueLabel,
			];
		}

		return $result;
	}
}
