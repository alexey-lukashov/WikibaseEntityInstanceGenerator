<?php
namespace MediaWiki\Extension\WikibaseEntityInstanceGenerator;

use DataValues\StringValue;
use Wikibase\DataModel\Snak\PropertyValueSnak;
use Html;
use Wikibase\Repo\Specials\SpecialNewItem;
use SpecialPage;
use Wikibase\DataModel\Services\Statement\GuidGenerator;
use Wikibase\Lib\Store\EntityNamespaceLookup;
use Wikibase\Lib\Store\EntityTitleLookup;
use Wikibase\Repo\Store\TermsCollisionDetector;
use Wikibase\Repo\SummaryFormatter;
use Wikibase\Repo\Validators\TermValidatorFactory;
use Wikibase\Repo\WikibaseRepo;
use Wikibase\Lib\SettingsArray;
use Wikibase\DataModel\Services\Lookup\LegacyAdapterItemLookup;
use Wikibase\DataModel\Services\Lookup\LegacyAdapterPropertyLookup;
use Wikibase\Repo\Specials\SpecialPageCopyrightView;
use Wikibase\Repo\CopyrightMessageBuilder;
use Wikibase\Repo\EditEntity\MediawikiEditEntityFactory;
use SiteLookup;
use Wikibase\DataModel\Entity\EntityDocument;
use Wikibase\Lib\FormatableSummary;
use Wikibase\Repo\Store\Store;
use Wikibase\DataModel\Entity\ItemId;
use Wikibase\DataModel\Entity\PropertyId;
use Wikibase\DataModel\Snak\Snak;
use Wikibase\DataModel\Snak\SnakList;
use Wikibase\DataModel\Statement\Statement;
use DataValues\DataValue;

class NewItem extends SpecialNewItem
{

    private $guidGenerator;

    public function __construct(SpecialPageCopyrightView $copyrightView, EntityNamespaceLookup $entityNamespaceLookup, SummaryFormatter $summaryFormatter, EntityTitleLookup $entityTitleLookup, MediawikiEditEntityFactory $editEntityFactory, SiteLookup $siteLookup, TermValidatorFactory $termValidatorFactory, TermsCollisionDetector $termsCollisionDetector)
    {
        parent::__construct($copyrightView, $entityNamespaceLookup, $summaryFormatter, $entityTitleLookup, $editEntityFactory, $siteLookup, $termValidatorFactory, $termsCollisionDetector);
        $this->guidGenerator = new GuidGenerator();
    }

    public static function f(EntityNamespaceLookup $entityNamespaceLookup, EntityTitleLookup $entityTitleLookup, TermsCollisionDetector $itemTermsCollisionDetector, SettingsArray $repoSettings, TermValidatorFactory $termValidatorFactory):
        self
        {
            $wikibaseRepo = WikibaseRepo::getDefaultInstance();

            $copyrightView = new SpecialPageCopyrightView(new CopyrightMessageBuilder() , $repoSettings->getSetting('dataRightsUrl') , $repoSettings->getSetting('dataRightsText'));

            return new self($copyrightView, $entityNamespaceLookup, $wikibaseRepo->getSummaryFormatter() , $entityTitleLookup, $wikibaseRepo->newEditEntityFactory() , $wikibaseRepo->getSiteLookup() , $termValidatorFactory, $itemTermsCollisionDetector);
        }

        protected function saveEntity(EntityDocument $item, FormatableSummary $summary, $token, $flags = EDIT_UPDATE)
        {
            $status = parent::saveEntity($item, $summary, $token, $flags);

            if (!$status->isGood())
            {
                return $status;
            }

            $typeParam = $this->parts[0] ?? null;
            $varParam = $this->parts[1] ?? null;
            $itemValueParam = $this->parts[2] ?? null;

            if (!empty($typeParam) and !empty($varParam) and !empty($itemValueParam))
            {
                NewItem::process($typeParam, $varParam, $itemValueParam, function ($propertyId, $property) use ($item)
                {
                    $snak = new PropertyValueSnak($propertyId, new StringValue('default "' . $property->getLabels()
                        ->getByLanguage('en')
                        ->getText() . '" value for ' . $item->getLabels()
                        ->getByLanguage('en')
                        ->getText()));

                    $item->getStatements()
                        ->addNewStatement($snak, null, null, $this
                        ->guidGenerator
                        ->newGuid($item->getId()));
                }
                , function ($propertyId, $property, $dataValue, $value) use ($item)
                {
                    $snak = new PropertyValueSnak($propertyId, $dataValue);

                    array_push($this->snaks, $snak);

                    $item->getStatements()
                        ->addNewStatement($snak, null, null, $this
                        ->guidGenerator
                        ->newGuid($item->getId()));
                });

            }

            return parent::saveEntity($item, $summary, $token, EDIT_UPDATE);
        }

        protected function getFormFields()
        {
            $formFields = parent::getFormFields();
            $formFields[self::FIELD_LABEL]['default'] = '';
            $formFields[self::FIELD_DESCRIPTION]['default'] = '';
            return $formFields;
        }

        public static function process($typeParam, $varParam, $itemValueParam, $stringPropertyCallback, $itemPropertyCallback, $parentItemCallback = null)
        {
            $itemLookup = new LegacyAdapterItemLookup(WikibaseRepo::getStore()->getEntityLookup(Store::LOOKUP_CACHING_RETRIEVE_ONLY));

            $propertyLookup = new LegacyAdapterPropertyLookup(WikibaseRepo::getStore()->getEntityLookup(Store::LOOKUP_CACHING_RETRIEVE_ONLY));

            $parentItem = $itemLookup->getItemForId(new ItemId($typeParam));
            $statements = $parentItem->getStatements();
            if ($parentItemCallback != null)
            {
                $parentItemCallback($parentItem);
            }
            $varProperty = $propertyLookup->getPropertyForId(new PropertyId($varParam));

            foreach ($statements as $statement)
            {

                $propertyId = $statement->getPropertyId();
                if (strcasecmp($varParam, $propertyId->getSerialization()) == 0)
                {

                    $property = $propertyLookup->getPropertyForId($propertyId);
                    $labels = $property->getLabels();

                    if (strcasecmp('value', $statement->getMainSnak()
                        ->getType()) == 0)
                    {
                        $pId = $statement->getMainSnak()
                            ->getDataValue()
                            ->getEntityId();
                        $p = $propertyLookup->getPropertyForId($pId);

                        $dv = null;
                        $v = null;

                        foreach ($statement->getQualifiers() as $qs)
                        {
                            if (strcasecmp('value', $qs->getType()) == 0)
                            {
                                if (strcasecmp($itemValueParam, $qs->getPropertyId()
                                    ->getSerialization()) == 0)
                                {
                                    $dv = $qs->getDataValue();
                                    $v = $itemLookup->getItemForId($dv->getEntityId());
                                    break;
                                }
                            }
                        }

                        if ($v == null and strcasecmp('string', $p->getDataTypeId()) == 0)
                        {
                            $stringPropertyCallback($pId, $p);
                        }
                        else if ($v != null)
                        {
                            $itemPropertyCallback($pId, $p, $dv, $v);
                        }
                    }
                }
            }
        }
    }

    class Special extends SpecialPage
    {

        private $newItem;

        public function __construct(NewItem $newItem)
        {
            parent::__construct("WikibaseEntityInstanceGenerator");
            $this->newItem = $newItem;
        }

        public static function factory(EntityNamespaceLookup $entityNamespaceLookup, EntityTitleLookup $entityTitleLookup, TermsCollisionDetector $itemTermsCollisionDetector, SettingsArray $repoSettings, TermValidatorFactory $termValidatorFactory) : self
        {
            return new self(NewItem::f($entityNamespaceLookup, $entityTitleLookup, $itemTermsCollisionDetector, $repoSettings, $termValidatorFactory));
        }

        public function execute($subPage)
        {
            parent::execute($subPage);

            $this
                ->newItem
                ->execute($subPage);

            $request = $this->getRequest();

            $parts = ($subPage === '' ? [] : explode('/', $subPage));
            $typeParam = $parts[0] ?? null;
            $varParam = $parts[1] ?? null;
            $itemValueParam = $parts[2] ?? null;

            $out = $this->getOutput();

            if (!empty($typeParam) and !empty($varParam) and !empty($itemValueParam))
            {

                $out->addHTML('<div class="oo-ui-panelLayout-framed oo-ui-panelLayout-padded oo-ui-panelLayout-framed">');

                NewItem::process($typeParam, $varParam, $itemValueParam, function ($propertyId, $property) use ($out)
                {
                    $out->addHTML('<li><b>' . $property->getLabels()
                        ->getByLanguage('en')
                        ->getText() . '</b> with default string value </li>');
                }
                , function ($propertyId, $property, $dataValue, $value) use ($out)
                {
                    $out->addHTML('<li><b>' . $property->getLabels()
                        ->getByLanguage('en')
                        ->getText() . '</b> with link to <b>' . $value->getLabels()
                        ->getByLanguage('en')
                        ->getText() . '</b> </li>');

                }
                , function ($parentItem) use ($out)
                {
                    $label = $parentItem->getLabels()
                        ->getByLanguage('en')
                        ->getText();
                    $out->addHTML(Html::rawElement('p', [], '* By clicking "Create", wikibase will create an instance of "' . $label . '" with fields:'));
                    $out->addHTML('<ul>');
                    $out->setPageTitle('Create a new "' . $label . '"');
                });

                $out->addHTML('</div></ul>');
            }
            else
            {
                $out->setPageTitle("Create New Item");
            }
        }
    }
    
