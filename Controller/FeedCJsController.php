<?php
App::uses('FeedsController', 'Feeds.Controller');

/**
 * See for params http://help.cj.com/en/web_services/product_catalog_search_service_rest.htm
 */

class _FeedCJsController extends FeedsController {
		
	public $uses = array('Feeds.FeedCJ');
	
	public $viewPath = 'Feeds';
    
    //Array of search categories
    // $value => 'Dispaly Name'
    public $categories = array(
        "Mens Men's" => "Men's",
        "Womens Women's" => "Women's",
    );
	
	public $allowedActions = array('get_categories');
	
	public function index () {
		//Defaults    
		try{
			$conditions = array();
			
			if(!empty($this->request['named'])) {
				$conditions = $this->request['named'];
			}
            
            //Create a fake $condtions array for extract
            $condExtract = $conditions;
            //Clean up the array, so values can be extracted
            foreach($condExtract as $k => $v) {
                $k = str_replace('-', '_', $k);
                unset($condExtract[$k]);
                $condExtract[$k] = $v;
            }
            
            //Create Variables for all passed $conditions
            extract($condExtract);
            
            //Create Defaults
            $keywords = isset($keywords) ? $keywords : '';
            $category = isset($category) ? $category : '';
            $records_per_page = isset($records_per_page) ? $records_per_page : 50;
            
            //This is how CJ feed handles category
            if (isset($conditions['category'])) {
                unset($conditions['category']);
            }
            
            // fix the conditions array for commission juction
            // @todo - this would be better to be params in the app controller, and the params
            // Handled by each feed controller. Right now its not scalable
            if(!empty($keywords)) {
                $keyworksarr = explode(' ', $keywords);
                foreach($keyworksarr as $k => $keyword) {
                    if(strpos($keyword, '+') === FALSE) {
                        $keyword = '+'.$keyword;
                    }
                    $keyworksarr[$k] = $keyword;
                }
                $conditions['keywords'] = implode(' ', $keyworksarr);
            }else {
                $conditions['keywords'] = $keywords;
            }
            
            $conditions['keywords'] = $conditions['keywords'].' +' . $category. ' ' .$this->defaultKeywords;
            $conditions['records-per-page'] = $records_per_page;
           
			$results = $this->FeedCJ->find('all', array(
				'conditions' => $conditions,
			));
			
			$products = $results['FeedCJ']['products']['product'];
			if (in_array('Ratings', CakePlugin::loaded()) && !empty($products)) {
				foreach($products as $k => $product) {
					$products[$k]['ratings'] = $this->_getProductRating($product);
				}
			}
		}catch(Exception $e) {
			$this->Session->setFlash($e);
		}
        
		$this->set('pagetitle', 'Shop for Products');
        $this->set('categories', $this->categories);
        $this->set('keywords', str_replace('+', '', $keywords));
        $this->set('category', $category);
        $this->set('records_per_page', $records_per_page);
		$this->set('products', $products);
		$this->set('pageNumber', $results['FeedCJ']['products']['@page-number']);
		$this->set('recPerPage', $results['FeedCJ']['products']['@records-returned']);
		$this->set('totalMatches', $results['FeedCJ']['products']['@total-matched']);
		
	}

	public function view($id = null) {
		
		if($id == null) {
			throw new NotFoundException('Could not find that product');
		}else {
			$this->FeedCJ->id = $id;
		}
		
		$results = $this->FeedCJ->find('first');
		$product = $results['FeedCJ']['products']['product'];
		
		/** @todo Probably should be in a custom controller **/
		
//		// get user's sizing data if possible
		$fromUsers = null;
//		try {
//		   $this->loadModel('Users.UserMeasurement');
//		   $fromUsers = $this->UserMeasurement->findSimilarUsers($this->Auth->user('id'));
//		} catch(MissingModelException $e) {		
//			// guess we're not using Measurement data
//		}
		
		// get the ratings of this item if possible
		if (in_array('Ratings', CakePlugin::loaded()) && !empty($results)) {
				$product['ratings'] = $this->_getProductRating($product);
		};
		/** end change scope **/

		$this->set('title_for_layout', $product['name'] . ' | ' . __SYSTEM_SITE_NAME);
		$this->set('product', $product);
	}
	
	
	
	public function advertisers ($keywords = array()) {
		
		if(!empty($this->request['named'])) {
			$advertiserName = $this->request['named']['name'];
		}
		$advertisers = $this->FeedCJ->getAdvertisers($keywords, $advertiserName);
		$this->set('advertisers', $advertisers);
		
	}
	
	

	public function retrieveItems ($type, $userId) {
		$favorites = $this->Favorite->getFavorites($userId, array('type' => $type));

		foreach ( $favorites as $favorite ) {
			$this->FeedCJ->id = $favorite['Favorite']['foreign_key'];
			$results = $this->FeedCJ->find('first');
			$items[] = $results['FeedCJ']['products']['product'];
		}
        
        if (in_array('Ratings', CakePlugin::loaded()) && !empty($items)) {
                foreach($items as $k => $item) {
                      $items[$k]['ratings'] = $this->_getProductRating($item);
                }
        }
        
		return $items;
	}
	
	
	public function __construct($request = null, $response = null) {
	
		//Adds Rateable Helpers.
		if (in_array('Ratings', CakePlugin::loaded())) {
			$this->helpers[] = 'Ratings.Rating';
			$this->uses[] = 'Ratings.Rating';
		}
		
		//Adds Favorable Helpers
		if (in_array('Favorites', CakePlugin::loaded())) {
			$this->helpers[] = 'Favorites.Favorites';
			$this->uses[] = 'Favorites.Favorite';
		}
		
		parent::__construct($request, $response);
	}
	
	public function beforeRender() {
		parent::beforeRender();
		
		//Adds User Favorites to Views
		if (in_array('Favorites', CakePlugin::loaded())) {
			$userId = $this->Session->read('Auth.User.id');
			$this->set('userFavorites', $this->Favorite->getAllFavorites($userId));
		}
		
	}
	
	protected function _getProductRating ($product, $userids = false) {
		if ( !empty($product['manufacturer-name']) && (!empty($product['manufacturer-sku']) || !empty($product['upc']))) {
                $id = implode('__', array($product['manufacturer-name'], $product['manufacturer-sku'], $product['upc']));
                
                return $this->_getRating($id, $userids);
            }else {
            	return array();
            }
	}
	
	protected function _getRating($id, $fromUsers = false) {
		
		$conditions = array('Rating.foreign_key' => $id);
		
		if ( $fromUsers ) {
			$conditions[] = array('Rating.user_id' => $fromUsers);
		}
		
		$ratings['Ratings'] = $this->Rating->find('all', array(
				'conditions' => $conditions,
			));
		$overall = array();
		$subratings = array();
		
		if(!empty($ratings['Ratings'])) {
			foreach($ratings['Ratings'] as $k => $rating) {
				$type = $rating['Rating']['type'];
				if(empty($type)) {
					$overall[] = $rating['Rating']['value'];
				}else{
					$subratings[$type][] = $rating['Rating']['value'];
				}
			}
		}else {
			return array();
		}
		
		$ratings['overall'] = !empty($overall) ? array_sum($overall)/count($overall) : $overall;
		if(!empty($subratings)) {
			foreach($subratings as $t => $sub) {
				$ratings['SubRatings'][$t] = array_sum($sub)/count($sub);
			}
		}
		
		return $ratings;
	}
	
	/**
	 * Function to return user categories
	 * @return the $categories aray
	 */
	
	public function get_categories() {
		$this->autoRender = false; //For request action only
		return $this->categories;
	}
}

if (!isset($refuseInit)) {
    class FeedCJsController extends _FeedCJsController {}
}

