<?php

namespace SilverStripe\Forms\Tests;

use SilverStripe\Control\Controller;
use SilverStripe\Forms\CurrencyField;
use SilverStripe\Forms\DateField;
use SilverStripe\Forms\NumericField;
use SilverStripe\Forms\Schema\FormSchema;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\TextField;
use SilverStripe\Forms\RequiredFields;
use SilverStripe\Forms\FormAction;
use SilverStripe\Forms\PopoverField;
use SilverStripe\Forms\FormField;
use LogicException;

class FormSchemaTest extends SapphireTest
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clear old messages
        $session = Controller::curr()->getRequest()->getSession();
        $session
            ->clear("FormInfo.TestForm.result")
            ->clear("FormInfo.TestForm.data");
    }

    public function testGetSchema()
    {
        $form = new Form(null, 'TestForm', new FieldList(), new FieldList());
        $formSchema = new FormSchema();
        $expected = json_decode(file_get_contents(__DIR__ . '/FormSchemaTest/testGetSchema.json') ?? '', true);

        $schema = $formSchema->getSchema($form);
        $this->assertIsArray($schema);
        $this->assertEquals($expected, $schema);
    }

    /**
     * @dataProvider provideGetSchemaException
     */
    public function testGetSchemaException(string $field, bool $expectException): void
    {
        $fields = [];
        if ($field === '<HasComponent>') {
            $fields[] = (new FormField('TestField'))->setSchemaComponent('MyPretendComponent');
        } elseif ($field === '<HasDataType>') {
            $fields[] = new class('TestField') extends FormField {
                protected $schemaDataType = FormField::SCHEMA_DATA_TYPE_CUSTOM;
            };
        } elseif ($field === '<None>') {
            $fields[] = new FormField('TestField');
        }
        $form = new Form(null, 'TestForm', new FieldList($fields));
        $formSchema = new FormSchema($form);
        if ($expectException) {
            $this->expectException(LogicException::class);
        } else {
            $this->expectNotToPerformAssertions();
        }
        $formSchema->getSchema($form);
    }

    public function provideGetSchemaException(): array
    {
        return [
            [
                'field' => '<HasComponent>',
                'expectException' => false,
            ],
            [
                'field' => '<HasDataType>',
                'expectException' => false,
            ],
            [
                'field' => '<None>',
                'expectException' => true,
            ],
        ];
    }

    public function testGetState()
    {
        $form = new Form(null, 'TestForm', new FieldList(), new FieldList());
        $formSchema = new FormSchema();
        $expected = [
            'id' => 'Form_TestForm',
            'fields' => [
                [
                    'id' => 'Form_TestForm_SecurityID',
                    'value' => $form->getSecurityToken()->getValue(),
                    'message' => null,
                    'data' => [],
                    'name' => 'SecurityID',
                ]
            ],
            'messages' => [],
            'notifyUnsavedChanges' => false
        ];

        $state = $formSchema->getState($form);
        $this->assertIsArray($state);
        $this->assertEquals($expected, $state);
    }

    public function testGetStateWithFormMessages()
    {
        $fields = new FieldList();
        $actions = new FieldList();
        $form = new Form(null, 'TestForm', $fields, $actions);
        $form->sessionMessage('All saved', 'good');
        $formSchema = new FormSchema();
        $expected = [
            'id' => 'Form_TestForm',
            'fields' => [
                [
                    'id' => 'Form_TestForm_SecurityID',
                    'value' => $form->getSecurityToken()->getValue(),
                    'data' => [],
                    'message' => null,
                    'name' => 'SecurityID',
                ]
            ],
            'messages' => [[
                'value' => 'All saved',
                'type' => 'good'
            ]],
            'notifyUnsavedChanges' => false
        ];

        $state = $formSchema->getState($form);
        $this->assertIsArray($state);
        $this->assertJsonStringEqualsJsonString(json_encode($expected), json_encode($state));
    }

    public function testGetStateWithFieldValidationErrors()
    {
        $fields = new FieldList(new TextField('Title'));
        $actions = new FieldList();
        $validator = new RequiredFields('Title');
        $form = new Form(null, 'TestForm', $fields, $actions, $validator);
        $form->clearMessage();
        $form->loadDataFrom(
            [
            'Title' => null,
            ]
        );
        $this->assertFalse($form->validate()->isValid());
        $formSchema = new FormSchema();
        $expected = [
            'id' => 'Form_TestForm',
            'fields' => [
                [
                    'id' => 'Form_TestForm_Title',
                    'value' => null,
                    'message' =>  [
                        'value' => '"Title" is required',
                        'type' => 'required'
                    ],
                    'data' => [],
                    'name' => 'Title',
                ],
                [
                    'id' => 'Form_TestForm_SecurityID',
                    'value' => $form->getSecurityToken()->getValue(),
                    'message' => null,
                    'data' => [],
                    'name' => 'SecurityID',
                ]
            ],
            'messages' => [],
            'notifyUnsavedChanges' => false
        ];

        $state = $formSchema->getState($form);
        $this->assertIsArray($state);
        $this->assertJsonStringEqualsJsonString(json_encode($expected), json_encode($state));
    }

    public function testGetNestedSchema()
    {
        $form = new Form(
            null,
            'TestForm',
            new FieldList(new TextField("Name")),
            new FieldList(
                (new FormAction("save", "Save"))
                    ->setIcon('save'),
                (new FormAction("cancel", "Cancel"))
                    ->setUseButtonTag(true),
                $pop = new PopoverField(
                    "More options",
                    [
                    new FormAction("publish", "Publish record"),
                    new FormAction("archive", "Archive"),
                    ]
                )
            )
        );
        $formSchema = new FormSchema();
        $expected = json_decode(file_get_contents(__DIR__ . '/FormSchemaTest/testGetNestedSchema.json') ?? '', true);
        $schema = $formSchema->getSchema($form);

        $this->assertIsArray($schema);
        $this->assertEquals($expected, $schema);
    }

    /**
     * Test that schema is merged correctly
     */
    public function testMergeSchema()
    {
        $publishAction = FormAction::create('publish', 'Publish');
        $publishAction->setIcon('save');
        $publishAction->setSchemaData(['data' => ['buttonStyle' => 'primary']]);
        $schema = $publishAction->getSchemaData();
        $this->assertEquals(
            [
                'icon' => 'save',
                'buttonStyle' => 'primary',
            ],
            $schema['data']
        );
    }

    public function testSchemaValidation()
    {
        $form = new Form(
            null,
            'TestForm',
            new FieldList(
                TextField::create("Name")
                    ->setMaxLength(40),
                new DateField("Date"),
                new NumericField("Number"),
                new CurrencyField("Money")
            ),
            new FieldList(),
            new RequiredFields('Name')
        );
        $formSchema = new FormSchema();
        $schema = $formSchema->getSchema($form);
        $expected = json_decode(file_get_contents(__DIR__ . '/FormSchemaTest/testSchemaValidation.json') ?? '', true);
        $this->assertIsArray($schema);
        $this->assertEquals($expected, $schema);
    }
}
