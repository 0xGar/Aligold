<?php

	class AliGoldPutter {

		var $AGG;
		var $variationCombinations;
		var $attributes;
		var $categories;
		var $categoryIds;
		var $sku;
		var $imageGallery;
		var $imageGalleryIds;
		var $productId;
		var $settings;

		function __construct($aliGoldGetter) {
			$this->AGG = $aliGoldGetter;
			return $this;
		}

		function run($settings){
			if (!isset($this->AGG)){
				echo "AliGoldGetter is not set.";
				exit();
			}
			$this->settings=$settings;

			$this->setAttributes()->
			setVariations()->
			setSku()->
			setCategories()->
			setGalleryImages()->
			putImageGallery()->
			putCategories()->
			putVariationImagesToGallery()->
			putProduct()->
			putVariations();

			$this->AGG=null;
		}

		/*
			Function:			setAttributes(...)
			Description:	Set all attributes from AliExpress JSON.
		*/
		function setAttributes(){
			$attributes=$this->AGG->getAttributes();
			if (count($attributes) >0) { $this->attributes=$attributes; }
			return $this;
		}

		/*
			Function:			putVariationImagesToGallery(...)
			Description:	Upload variation images to WordPress & update class variable
		*/
		function putVariationImagesToGallery() {
			$imagesDone=[];
			for($i=0;$i < count($this->variationCombinations);$i++){
				$vc=$this->variationCombinations[$i];
				if ($vc["image"]!=='') {
					$alreadyAdded=0;
					foreach($imagesDone as $img){
						if ($img["url"]===$vc["image"]){
							$alreadyAdded=$img["id"];
							break;
						}
					}
					if ($alreadyAdded==0) {
						$imageId = $this->putImage($vc["image"]);
						$imagesDone[]=array("url"=>$vc["image"],"id"=>$imageId);
					} else {
						$imageId=$alreadyAdded;
					}
					$this->variationCombinations[$i]["imageId"]=$imageId;
				}
			}
			return $this;
		}

		/*
			Function: setVariations()
			Description: Set AliExpress product variations.
		*/
		function setVariations() {
			$variationCombinations=$this->AGG->getVariations();
			if ($variationCombinations > 0) {
				$this->variationCombinations=$variationCombinations;
			}
			return $this;
		}

		/*
			Function: setSku()
			Description: Set AliExpress product sku.
		*/
		function setSku() {
				$sku=$this->AGG->getSKU();
				if ($sku >0) { $this->sku=$sku; }
				return $this;
		}

		/*
			Function:		setCategories(...)
			Description: 	Sets a list of product category name&Ids
							from the AliExpress product JSON.
		*/

		function setCategories() {
			$categories=$this->AGG->getCategories();
			if (count($categories)>0){ $this->categories=$categories; }
			return $this;
		}

		/*
			Function:			setImages(...)
			Description: 	Sets gallery images from AliExpress JSON.
		*/
		function setGalleryImages() {
			$imageGallery=$this->AGG->getGalleryImages();
			if (count($imageGallery)>0){ $this->imageGallery=$imageGallery; }
			return $this;
		}

		/*
			Function:			setCategories(...)
			Description: 	Gets Woocommerce product category IDs. Creates
										new categories when the category doesn't exist.
		*/
		function putCategories() {
			$categoryIds=[];
			$previousId=0;
			foreach($this->categories as $cat){
				$id = term_exists($cat["name"], "product_cat",$previousId==0?null:$previousId);
				if ($id == 0 || $id === null) {
					$id = wp_insert_term(
						$cat["name"],
						'product_cat',
						array(
							"parent"		=> $previousId,
							"slug"			=> $cat["id"]."-slug",
							"description"	=> ""
						)
            		)["term_id"];
				} else {
					$id=$id["term_id"];
				}
				$previousId=$id;
				$categoryIds[]=$id;
			}
			$this->categoryIds = $categoryIds;
			return $this;
		}


		/* Function: putProduct()
		 	 Description: Creates a new WooCommerce product.
			 Input: AliExpress JSON object. See toJSON(...).
			 Output: Product ID.
		*/
		function putProduct() {
			//$this->putProductCheck();
			$wc_p = new WC_Product_Variable();
			$wc_p->set_description("");
			$wc_p->set_sku($this->sku);
			$wc_p->set_category_ids($this->categoryIds);
			$wc_p->set_gallery_image_ids($this->imageGalleryIds);
			$wc_p->set_image_id($this->imageGalleryIds[0]);
			$wc_p->set_attributes($this->attributes);
			$wc_p->save();
			print_r($wc_p->get_id());
			$this->productId=$wc_p->get_id();
			return $this;
		}

		function putProductCheck() {
			$error=false;
			$errorMsg=[];
			if (!isset($this->sku)){
							$errorMsg[]="setSku() has not been called.";
							$error=true;
			}
			if (!isset($this->categories)){
							$errorMsg[]="setCategories() has not been called.";
							$error=true;
			}
			if (!isset($this->variationCombinations)){
							$errorMsg[]="setVariations() has not been called.";
							$error=true;
			}
			if (!isset($this->attributes)){
							$errorMsg[]="setAttributes() has not been called.";
							$error=true;
			}
			if (!isset($this->imageGalleryIds)){
							$errorMsg[]="putImageGallery() has not been called.";
							$error=true;
			}
			if ($error){
				$printer="Error: ";
				foreach($errorMsg as $e){
					$printer.=$e . "<br>";
				}
				echo $printer;
				exit();
			}
		}

		/*
			Function:			putVariations(...)
			Description: 	Add variations to WooCommerce product
			Input:				$id (INT) -> ID of the product
		*/
		function putVariations() {
			print_r($this->variationCombinations);
			foreach($this->variationCombinations as $variationCombination){
				$wc_p = new WC_Product_Variation();
				$wc_p->set_parent_id($this->productId);
				$wc_p->set_attributes($variationCombination["combination"]);
				$wc_p->set_image_id($variationCombination["imageId"]);
				$wc_p->set_regular_price($variationCombination["price"]);
				//$wc_p->variation_is_active($variationCombination->isActive);
				$wc_p->save();
			}
			return $this;
		}

		/*
			Function:			putImageGallery(...).
			Description:	Uploads all gallery images from AliExpress JSON to Wordpress.
		*/
		function putImageGallery(){
			$imageGalleryIds=[];
			foreach ($this->imageGallery as $imgUrl){
				$id=$this->putImage($imgUrl);
				$imageGalleryIds[]=$id;
			}
			if(count($imageGalleryIds)>0){$this->imageGalleryIds=$imageGalleryIds;}
			return $this;
		}

		/*
			Function:			uploadImage(...).
			Description:	Downloads then uploads an image to the WordPress gallery.
			Input: 				URL of the image.
			Credit: https://wordpress.stackexchange.com/questions/50123/image-upload-from-url
		*/

		function putImage($path) {
				$filename=basename($path);
				$extension=".".end(explode(".", $filename));
				$filename=uniqid() . md5($filename) . $extension;
				$uploaddir = wp_upload_dir();
				$uploadfile = $uploaddir['path'] . '/' . $filename;
				$contents= file_get_contents($path);
				$savefile = fopen($uploadfile, 'w');
				fwrite($savefile, $contents);
				fclose($savefile);
				$wp_filetype = wp_check_filetype(basename($filename), null );
				$attachment = array(
				    'post_mime_type' => $wp_filetype['type'],
				    'post_title' => $filename,
				    'post_content' => '',
				    'post_status' => 'inherit'
				);
				$attach_id = wp_insert_attachment($attachment, $uploadfile);
				$imagenew = get_post($attach_id);
				$fullsizepath = get_attached_file($imagenew->ID);
				$attach_data = wp_generate_attachment_metadata($attach_id, $fullsizepath);
				wp_update_attachment_metadata($attach_id, $attach_data);
				return $attach_id;
		}

	}

?>
