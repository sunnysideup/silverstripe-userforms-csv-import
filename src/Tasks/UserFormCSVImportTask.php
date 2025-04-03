<?php

namespace Sunnysideup\UserformsCsvImport\Tasks;

use DNADesign\ElementalUserForms\Model\ElementForm;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DB;
use SilverStripe\UserForms\Model\Submission\SubmittedForm;
use SilverStripe\UserForms\Model\Submission\SubmittedFormField;

class UserFormCSVImportTask extends BuildTask
{

    protected $title = 'UserForm CSV Import Task';

    protected $description = 'Import UserForm submissions from a CSV file (requires an ID column, intended for elemental forms)';

    private static $segment = 'UserFormCSVImportTask';


    public function run($request)
    {
        // Parse options
        $arguments = (array) $_SERVER['argv'];
        $file = $arguments[2] ?? null;
        if (! $file) {
            $this->output('Please provide a CSV file path as the first argument');
            return;
        } else {
            $formID = $arguments[3] ?? null;
            if (! $formID) {
                $this->output('Please provide a form ID as the second argument');
                return;
            }
            $this->output('IMPORTING: ' . $file);
            $csvMap = $this->importCSV($file);
            $this->makeRoom($csvMap);
            $this->importFromArray($csvMap, $formID);
        }
    }

    protected function importCSV($filename): array
    {
        $dataArray = [];
        if (($handle = fopen($filename, "r")) !== FALSE) {
            while ($data = fgetcsv($handle)) {
                array_push($dataArray, $data);
            }
            fclose($handle);
        }

        array_walk(
            $dataArray,
            function (&$a) use ($dataArray) {
                $a = array_combine($dataArray[0], $a);
            }
        );
        array_shift($dataArray);

        return $dataArray;
    }

    protected function importFromArray($csvMap, $formID)
    {
        $count = 0;
        $form = ElementForm::get()->byID($formID);
        if (! $form) {
            $this->output('Form not found');
            return;
        }
        foreach ($csvMap as $row) {
            $id = $row['ID'];
            DB::query('INSERT INTO
                "SubmittedForm" ("ID", "ParentID", "ParentClass", "Created", "LastEdited")
                VALUES (' . $id . ', ' . $formID . ', \'DNADesign\\\ElementalUserForms\\\Model\\\ElementForm\', \'' . $row['Created'] . '\', \'' . $row['Created'] . '\')');
            foreach ($row as $key => $value) {
                if ($key === 'ID' || $key === 'Created') {
                    continue;
                } else {
                    $field = $form->Fields()->filter('Title', $key)->first();
                    if (! $field) {
                        $this->output('Field not found: ' . $key);
                        continue;
                    }
                    $this->output('Importing ' . $key . ' for submission ' . $id);
                    $name = $field->Name;
                    $class = $field->ClassName;
                    $createFileObjects = false;
                    if ($class === 'SilverStripe\UserForms\Model\EditableFormField\EditableFileField') {
                        $createFileObjects = true;
                        $class = 'SilverStripe\\\UserForms\\\Model\\\Submission\\\SubmittedFileField';
                        $this->output('Using custom class for file field: ' . $class);
                    } else {
                        $class = 'SilverStripe\\\UserForms\\\Model\\\Submission\\\SubmittedFormField';
                    }
                    DB::query('INSERT INTO
                        "SubmittedFormField" ("ParentID", "Name", "Value", "Created", "LastEdited", "Title", "ClassName")
                        VALUES (' . $id . ', \'' . $name . '\', \'' . $value . '\', \'' . $row['Created'] . '\', \'' . $row['Created'] . '\', \'' . $key . '\', \'' . $class . '\')');
                    $fieldID = DB::get_conn()->getGeneratedID('SubmittedFormField');
                    if ($createFileObjects) {
                        if ($value) {
                            $this->makeFileObjects($value, $fieldID);
                        }
                    }
                }
            }
            $count++;
        }
        $this->output('Imported ' . $count . ' submissions');
    }

    protected function makeFileObjects($fieldData, $formfieldID)
    {
        $this->output('Creating file objects for field data: ' . $fieldData);
        $sections = explode(' - ', $fieldData);

        $fileLink = explode('"', $sections[1])[1];
        $linkNoQuery = explode('?', $fileLink)[0];

        $linkArray = explode('/', $linkNoQuery);
        $processedFileLocation = array_slice($linkArray, 4);

        $filename = $sections[0];
        $filenameLocation = array_slice($processedFileLocation, 0);
        array_pop($filenameLocation); //protected hash
        $filenameLocation = implode('/', $filenameLocation);

        $fileFilename = $filenameLocation . '/' . $filename;

        $processedFileLocation = implode('/', $processedFileLocation);

        $file = File::create();
        $file->setFromString(file_get_contents('public/assets/.protected/' . $processedFileLocation), $fileFilename, null, null, [
            'visibility' => AssetStore::VISIBILITY_PROTECTED,
        ]);

        $file->ParentID = 0; // Change if needed

        $file->write();

        $fileID = $file->ID;

        // Now setup submittedfilefield object
        DB::query('INSERT INTO
            "SubmittedFileField" ("ID", "UploadedFileID")
            VALUES (' . $formfieldID . ', ' . $fileID . ')');
    }

    protected function makeRoom($csvMap)
    {
        // Check for any double up IDs
        // For our use case, we move the EXISTING ID to the next available ID, to make room for the CSV import
        $largestNewID = $this->getLargestNewID($csvMap);
        foreach ($csvMap as $row) {
            $id = $row['ID'];
            $existing = SubmittedForm::get()->filter('ID', $id)->first();
            if ($existing !== null) {
                $largestID = max($largestNewID, SubmittedForm::get()->max('ID'));
                $nextID = (int) $largestID + 1;
                $this->output('Moving existing ID ' . $id . ' to ' . $nextID);
                $this->moveExistingID($existing, $nextID);
            }
        }
    }

    protected function moveExistingID($existing, $newID)
    {
        $oldID = $existing->ID;
        DB::query('UPDATE "SubmittedForm" SET "ID" = ' . $newID . ' WHERE "ID" = ' . $oldID);
        DB::query('UPDATE "SubmittedFormField" SET "ParentID" = ' . $newID . ' WHERE "ParentID" = ' . $oldID);
    }

    protected function getLargestNewID($csvMap)
    {
        $largestNewID = 0;
        foreach ($csvMap as $row) {
            $this->output('Checking ID: ' . $row['ID']);
            $id = $row['ID'];
            if ($id > $largestNewID) {
                $largestNewID = $id;
            }
        }
        return $largestNewID;
    }

    protected function output($message)
    {
        echo $message . PHP_EOL;
    }
}
