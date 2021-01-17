# Wikibase Entity Instance Generator

The Wikibase Entity Instance Generator extension provides a way for users to generate an Item form with predefined values and references.

Tested on Wikibase 1.36

# Installation

**Download from Git**
```
cd extensions
git clone https://github.com/alexey-lukashov/WikibaseEntityInstanceGenerator.git WikibaseEntityInstanceGenerator
```

At this point from your LocalSettings.php you can enable Wikibase Entity Instance Generator
```
wfLoadExtension( 'WikibaseEntityInstanceGenerator' );
```

Create configuration properties for "generated field" and "reference qualifier".

The "reference qualifier" with an Item value is added to the "reference value" to assign a link to another Item.

For example:
"generated field" is P1
"reference qualifier" is P2

Following request will create an item as an instance of Q1 generating string properties specified as P1("generated field") and assign item links for P1 with qualifier P2.
```
/Special:WikibaseEntityInstanceGenerator/Q1/P1/P2
```
