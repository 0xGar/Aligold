<?php



/***
--------------------------------------------------------------------------------
	Questions
--------------------------------------------------------------------------------

1. Why is this script so slow?
	WooCommerce itself is pretty slow when it comes to adding variations.
	It's a performance killer.

--------------------------------------------------------------------------------
	Features
--------------------------------------------------------------------------------

Import products from AliExpress to WooCommerce, including:
	- Gallery images
	- Variations + images + prices
	- Specifications
	- Categories

Limitations
		- AliExpress' description itself is not imported; instead, specifications
		are used as the description.
		- Shipping information is excluded.
		- Comments and ratings are not imported.

--------------------------------------------------------------------------------
	Coding: Important Definitions
--------------------------------------------------------------------------------

---------------------
Attribute & Variation
----------------------
An attribute is another word for "category",
and a variation is the set of a category.
Example: "Color" is an attribute and "blue" is a variation.

----------------------
Variation Combination
----------------------
Best explained through an example. In terms of T-shirts,
"blue" is just a color variation and "XXL" is just a size variation.
But when put together, they become combined. In otherwords,
they become a "combination of variations", or "variation combination."

--------------------------------------------------------------------------------
	Coding: AliExpress JSON structure
--------------------------------------------------------------------------------

All of AliExpress' product data is stored in JSON format,
found within their soure code. Finding product description
and image gallery URLs is rather straight forward. Correctly calculating
prices is a little bit more involved, however. This is because most products
have variations (e.g., T-shirt size) that are different prices,
and this information is not plainly available in the JSON;
it has to instead be deducated. This is done by:

1) Creating a key-value variation map, where the key is the variation ID
and the value is the relevant information about that variation.
For example, variation[19883828]=array("Color"=>"Red","ImageId"=>65,...).
(gathered from skuModule->productSKUPropertyList->skuPropertyValues.)

2) Referencing the price section of the JSON. This will contain many entires
along the lines of skuPropsId->"1928334;34848;384384". These numbers
reference product variations, hence needing the map above.

Using these two steps, we're able to construct what product vairation selections
lead to what prices (e.g., black shirt, S = 10.99, black shirt XXL=14.99):

$variationCombinations =
Array
(
		[0] => Array
				(
						[combination] => Array
								(
										[0] => Array
												(
														[Color] => green,
														[Size] => XL
												)

								)

						[price] => 7.19
						[imageId] =>
						[isActive] => 1
				)
			[1] => ...
			...
	)

--------------------------------------------------------------------------------
	Coding: Uploading to WooCommerce
--------------------------------------------------------------------------------

There's multiple ways to add/update WooCommerce products programmatically.
There's the API, but it's a bit more messy and convoluted. Instead,
this script directly uses their package -- a much cleaner approach.

*/

	class AliGoldGetter {

		var $json;

		function __construct($url) {
			$json=file_get_contents($url);
			$this->json = json_decode($json);
		}

		/*
			Function:			setAttributes(...)
			Description:	Set all attributes from AliExpress JSON.
			Input: 				AliExpress JSON. See toJson(...).
			Output: 			Array of attributes. E.g, ["Color","Size"]
		*/

		function getVariationAttributes(){
			$json=$this->json;
			$attributes=[];
			foreach($json->skuModule->productSKUPropertyList as $pspl){
					$variations=[];
					foreach($pspl->skuPropertyValues as $variation){
						$variations[]=strtolower($variation->propertyValueDisplayName);
					}
					$attribute = new WC_Product_Attribute();
					$attribute->set_id(0);
					$attribute->set_name(strtolower($pspl->skuPropertyName));
					$attribute->set_options($variations);
					$attribute->set_visible(false);
					$attribute->set_variation(true);
					$attributes[]=$attribute;
			}
			return $attributes;
		}

		function getVariations() {
			if (isset($this->settings["makeVariations"])){
				if ($this->settings["makeVariations"]!=true) {
					return [];
				}
			}

			$json = $this->json;
			$variationIdToProp=[];
			$variationCombinations=[];
			$attributeCount=0;

			/*
			  Map variation IDs to variation data.
				Example: $variationIdToName[1928382]=["Color"->"Black", "ImageId":60]
			*/

			foreach($json->skuModule->productSKUPropertyList as $pspl){
				$attributeCount++;
				$attributeName=strtolower($pspl->skuPropertyName); //Attribute name
				foreach($pspl->skuPropertyValues as $propValues) {
					$variationId=$propValues->propertyValueId;
					$variationName=strtolower($propValues->propertyValueDisplayName);
					if (isset($propValues->skuPropertyImagePath)){
						$image = $propValues->skuPropertyImageSummPath;
					} else {
						$image='';
					}
					$variationIdToProp[$variationId]=array(
						"variation"=>array("name"=>$attributeName,"value"=>$variationName),
						"image"=> $image
					);
				}
			}

			/*
				Create variation combinations
			*/

			foreach($json->skuModule->skuPriceList as $priceList){
				$variationCombination=[]; //Hold a variation combination
				$variationIds = explode(',',$priceList->skuPropIds); //Variation IDs
				if (count($variationIds) != $attributeCount){continue;}
				$combinationPrice = $priceList->skuVal->actSkuCalPrice; //Price for combination
				if (isset($this->settings["inflationPrice"])){
					$combinationPrice=$combinationPrice*$this->settings["inflationPrice"];
				}
				$isActive=$priceList->skuVal->isActivity; //Is variation combination active?
				$img = '';

				//Construct the variation combination
				foreach($variationIds as $variationId) {
					$vc=$variationIdToProp[$variationId]["variation"];
					$variationCombination[$vc["name"]]=$vc["value"];

					if ($img==='') { //Image exists for one of the variations? Use it.
						if (isset($variationIdToProp[$variationId]["image"])){
							$img = $variationIdToProp[$variationId]["image"];
						}
					}
				}

				$variationCombinations[]=array(
					"combination"=>$variationCombination,
					"price"=>$combinationPrice,
					"image"=>$img,
					"imageId"=>0, //Not processed yet; call uploadVariationImages()
					"isActive"=>$isActive
				);
			}
			return $variationCombinations;
		}

		/*
			Function: setSku()
			Description: Set AliExpress product sku.
		*/

		function getSku() {
			$sku=$this->json->commonModule->productId;
			return $sku;
		}

		/*
			Function: setSpecifications()
			Description: Set AliExpress product specification
		*/
		function getSpecificationAttributes() {
			$specifications=[];
			$json = $this->json;
			foreach($json->specsModule->props as $spec){
				$attribute = new WC_Product_Attribute();
				$attribute->set_id(0);
				$attribute->set_name(strtolower($spec->attrName));
				$attribute->set_options(array($spec->attrValue));
				$attribute->set_visible(true);
				$attribute->set_variation(false);
				$specifications[]=$attribute;
			}
			return $specifications;
		}
	
		/*
			Function: getKeywords();
			Description: Get the product's keywords
		*/
		function getKeywords(){
			return $this->json->pageModule->keywords;
		}

		/*
			Function: getTitle();
			Description: Get the name of the product
		*/
		function getTitle(){
			return $this->json->titleModule->subject;
		}

		/*
			Function: getMergedAttributes();
			Description: Gets all attributes that exist, variation attributes+specification attributes
		*/
		function getAttributes(){
			if (isset($this->settings["makeVariations"])){
				if ($this->settings["makeVariations"]!=true) {
					return $this->getSpecificationAttributes();
				}
			}
			return array_merge($this->getVariationAttributes(),$this->getSpecificationAttributes());
		}

		/*
			Function:			setCategories(...)
			Description: 	Sets a list of product category name&Ids
										from the AliExpress product JSON.
		*/

		function getCategories() {
			if (isset($this->settings["makeCategories"])){
				if ($this->settings["makeCategories"]!=true) {
					return [];
				}
			}
			$json=$this->json;
			$categories=[];
			foreach($json->crossLinkModule->breadCrumbPathList as $cat) {
				$name=$cat->name;
				$catId=$cat->cateId;
				if ($catId==0) { //Id of 0 = "Home" or "All Categories". Skip.
					continue;
				}
				$categories[]=array(
					"name"=>$name,
					"id"=>$catId
				);
			}
			return $categories;
		}

		/*
			Function:			setImages(...)
			Description: 	Sets gallery images from AliExpress JSON.
		*/

		function getGalleryImages() {
			$json=$this->json;
			//$imageGallery=[];
			//foreach ($json->imageModule->imagePathList as $img){
			$imageGallery=$json->imageModule->imagePathList;
			//}
			return $imageGallery;
		}

	}
?>
