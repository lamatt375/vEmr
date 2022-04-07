<?php

/**
 * @package   OpenEMR
 * @link      http://www.open-emr.org
 * @author    Ken Chapple <ken@mi-squared.com>
 * @copyright Copyright (c) 2021 Ken Chapple <ken@mi-squared.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU GeneralPublic License 3
 */

namespace OpenEMR\Services\Qdm\Services;

use OpenEMR\Cqm\Qdm\BaseTypes\AbstractType;
use OpenEMR\Cqm\Qdm\BaseTypes\Address;
use OpenEMR\Cqm\Qdm\BaseTypes\Code;
use OpenEMR\Cqm\Qdm\BaseTypes\DateTime;
use OpenEMR\Cqm\Qdm\BaseTypes\Telcom;
use OpenEMR\Cqm\Qdm\Id;
use OpenEMR\Cqm\Qdm\Identifier;
use OpenEMR\Cqm\Qdm\Patient;
use OpenEMR\Cqm\Qdm\PatientCharacteristicBirthdate;
use OpenEMR\Cqm\Qdm\PatientCharacteristicEthnicity;
use OpenEMR\Cqm\Qdm\PatientCharacteristicExpired;
use OpenEMR\Cqm\Qdm\PatientCharacteristicRace;
use OpenEMR\Cqm\Qdm\PatientCharacteristicSex;
use OpenEMR\Services\Qdm\Interfaces\QdmServiceInterface;

class PatientService extends AbstractQdmService implements QdmServiceInterface
{
    public function getSqlStatement()
    {
        $sql = "SELECT
                    pid,
                    fname,
                    lname,
                    DOB,
                    P.race,
                    RACE.notes AS race_code,
                    P.ethnicity,
                    ETHN.notes AS ethnicity_code,
                    P.sex,
                    SEX.codes AS sex_code,
                    P.street,
                    P.postal_code,
                    P.city,
                    P.state,
                    P.phone_home,
                    P.phone_cell,
                    P.country_code,
                    P.deceased_date,
                    P.deceased_reason
            FROM patient_data P
            LEFT JOIN list_options RACE ON RACE.list_id = 'race' AND P.race = RACE.option_id
            LEFT JOIN list_options ETHN ON ETHN.list_id = 'ethnicity' AND P.ethnicity = ETHN.option_id
            LEFT JOIN list_options SEX ON SEX.list_id = 'sex' AND P.sex = SEX.option_id
            ";
        return $sql;
    }

    public static function makeQdmIdentifier($pid)
    {
        return new Identifier(
            [
            'namingSystem' => 'OpenEMR pid',
            'value' => $pid
            ]
        );
    }

    public function makeQdmModel(array $record)
    {
        $id = self::makeQdmIdentifier($record['pid']);

        $qdmPatient = new Patient(
            [
            'patientName' => [
                'given' => $record['fname'],
                'middle' => $record['mname'] ?? '',
                'family' => $record['lname']
            ],
                'birthDatetime' => $record['DOB'],
                'id' => $id // From PatientExtension trait
            ]
        );

        // Create the address and add to model (for QRDA Cat 1 Export)
        $address = new Address(
            [
            'street' => [
                $record['street']
            ],
            'city' => $record['city'],
            'state' => $record['state'],
            'zip' => $record['postal_code'],
            'country' => $record['country_code']
            ]
        );
        $qdmPatient->addAddress($address);

        // Create the telcom and add to model (for QRDA Cat 1 Export)
        $telcom = new Telcom(
            [
            'value' => $record['phone_home']
            ]
        );
        $qdmPatient->addTelcom($telcom);

        $qdmPatient->extendedData = [
            'pid' => $record['pid']
        ];

        $pcdob = new PatientCharacteristicBirthdate(
            [
            'birthDatetime' => new DateTime(
                [
                'date' => $record['DOB']
                ]
            ),
            'dataElementCodes' => [
                new Code(
                    [
                    'code' => '21112-8',
                    'system' => '2.16.840.1.113883.6.1'
                    ]
                )
            ]
            ]
        );
        $qdmPatient->add_data_element($pcdob);

        // If th patient is deceased/expired, then add the Patient Characteristic Expired
        if (!empty($record['deceased_date'])) {
            $pcexp = new PatientCharacteristicExpired([
                'expiredDatetime' => $record['deceased_date'],
                'cause' => $this->makeQdmCode($record['deceased_reason'])
            ]);
            $qdmPatient->add_data_element($pcexp);
        }

        // Reference: https://phinvads.cdc.gov/vads/ViewCodeSystem.action?id=2.16.840.1.113883.6.238
        $pcr = new PatientCharacteristicRace(
            [
            'dataElementCodes' => [
                new Code(
                    [
                    'code' => $record['race_code'],
                    'system' => '2.16.840.1.113883.6.238'
                    ]
                )
            ]
            ]
        );
        $qdmPatient->add_data_element($pcr);

        $pce = new PatientCharacteristicEthnicity(
            [
            'dataElementCodes' => [
                new Code(
                    [
                    'code' => $record['ethnicity_code'],
                    'system' => '2.16.840.1.113883.6.238'
                    ]
                )
            ]
            ]
        );
        $qdmPatient->add_data_element($pce);

        $pcs = new PatientCharacteristicSex();
        // Get the code from the database and use our parent function to split by ':' Because in DB it looks like 'HL7:M'
        // These HL7 code types aren't in our code types service, so we hard-code it. TODO parse the cdavocabmap.xml into a service
        $code = $this->makeQdmCode($record['sex_code']);
        if ($code instanceof Code) {
            // http://www.hl7.org/documentcenter/public/standards/vocabulary/vocabulary_tables/infrastructure/vocabulary/vs_AdministrativeGender.html
            $code->system = '2.16.840.1.113883.5.1';
            $pcs->addCode($code);
        }
        $qdmPatient->add_data_element($pcs);

        return $qdmPatient;
    }
}
