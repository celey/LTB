<?php
class Slick_App_Dashboard_Blog_Submissions_Controller extends Slick_App_ModControl
{
    
    function __construct()
    {
        parent::__construct();
        $this->model = new Slick_App_Dashboard_Blog_Submissions_Model;
        $this->user = Slick_App_Account_Home_Model::userInfo();
		$this->tca = new Slick_App_LTBcoin_TCA_Model;
		$this->inventory = new Slick_App_Dashboard_LTBcoin_Inventory_Model;
		$this->meta = new Slick_App_Meta_Model;
		$this->postModule = $this->model->get('modules', 'blog-post', array(), 'slug');
		$this->catModule = $this->model->get('modules', 'blog-category', array(), 'slug');        
		$this->blogApp = $this->model->get('apps', 'blog', array(), 'slug');
		$this->blogSettings = $this->meta->appMeta($this->blogApp['appId']);
        $this->postModel = new Slick_App_Blog_Post_Model;
        $this->invite = new Slick_App_Account_Invite_Model;
    }
    
    function __install($moduleId)
    {
		$install = parent::__install($moduleId);
		if(!$install){
			return false;
		}
		
		$meta = new Slick_App_Meta_Model;
		$blogApp = $meta->get('apps', 'blog', array(), 'slug');
		$meta->updateAppMeta($blogApp['appId'], 'submission-fee', 1000, 'Article Submission Fee', 1);
		$meta->updateAppMeta($blogApp['appId'], 'submission-fee-token', 'LTBCOIN', 'Submission Fee Token', 1);
		
		$meta->addAppPerm($blogApp['appId'], 'canBypassSubmitFee');
		
		return $install;
	}
    
    public function init()
    {
		$output = parent::init();
		$tca = new Slick_App_LTBcoin_TCA_Model;
		$postModule = $tca->get('modules', 'blog-post', array(), 'slug');
		$this->data['perms'] = Slick_App_Meta_Model::getUserAppPerms($this->data['user']['userId'], 'blog');
		$this->data['perms'] = $tca->checkPerms($this->data['user'], $this->data['perms'], $postModule['moduleId'], 0, '');
		
        if(isset($this->args[2])){
			switch($this->args[2]){
				case 'view':
					$output = $this->showPosts();
					break;
				case 'add':
					$output = $this->addPost();
					break;
				case 'edit':
					$output = $this->editPost();
					break;
				case 'delete':
					$output = $this->deletePost();
					break;
				case 'preview':
					$output = $this->previewPost($output);
					break;
				case 'check-credits':
					$output = $this->checkCreditPayment();
					break;
				case 'trash':
					if(isset($this->args[3])){
						$output = $this->trashPost();
					}
					else{
						$output = $this->showPosts(1);
					}
					break;
				case 'restore':
					$output = $this->trashPost(true);
					break;
				case 'clear-trash':
					$output = $this->clearTrash();
					break;
				case 'compare':
					$output = $this->comparePostVersions();
					break;
				default:
					$output = $this->showPosts();
					break;
			}
		}
		else{
			$output = $this->showPosts();
		}
		$output['postModule'] = $this->postModule;
		$output['blogApp'] = $this->blogApp;
		$output['template'] = 'admin';
        $output['perms'] = $this->data['perms'];
       
        
        return $output;
    }
    
    /**
    * Shows a list of posts that the current user has submitted
    *
    * @return Array
    */
    private function showPosts($trash = 0)
    {
		$output = array('view' => 'list');
		$getPosts = $this->model->getAll('blog_posts', array('siteId' => $this->data['site']['siteId'],
															 'userId' => $this->data['user']['userId'],
															 'trash' => $trash), array(), 'postId');
															 
		$getContribPosts = $this->model->getUserContributedPosts($this->data);
		$getPosts = array_merge($getPosts, $getContribPosts);
															 
		$viewedComments = $this->meta->getUserMeta($this->data['user']['userId'], 'viewed-editorial-comments');
		if($viewedComments){
			$viewedComments = explode(',', $viewedComments);
		}															 
															
		$output['totalPosts'] = 0;
		$output['totalPublished'] = 0;
		$output['totalViews'] = 0;
		$output['totalComments'] = 0;
		$output['totalContributed'] = 0;
		$disqus = new Slick_API_Disqus;
		foreach($getPosts as $key => $row){
			$row['published'] = $this->model->checkPostApproved($row['postId']);
			$getPosts[$key]['published'] = $row['published'];
			$getPosts[$key]['author'] = $this->model->get('users', $row['userId'], array('userId', 'username', 'slug'));
			$postPerms = $this->tca->checkPerms($this->data['user'], $this->data['perms'], $this->postModule['moduleId'], $row['postId'], 'blog-post');
			$getPosts[$key]['perms'] = $postPerms;
			if($row['userId'] == $this->data['user']['userId']){
				$output['totalPosts']++;
				if($row['published'] == 1){
					$output['totalPublished']++;
				}
			}
			$output['totalViews']+=$row['views'];	
			$pageIndex = Slick_App_Controller::$pageIndex;
			$getIndex = extract_row($pageIndex, array('itemId' => $row['postId'], 'moduleId' => $this->postModule['moduleId']));
			$postURL = $this->data['site']['url'].'/blog/post/'.$row['url'];
			if($getIndex AND count($getIndex) > 0){
				$postURL = $this->data['site']['url'].'/'.$getIndex[count($getIndex) - 1]['url'];
			}			
			
			$comDiff = time() - strtotime($row['commentCheck']);
			$commentThread = false;
			if($comDiff > 1800){
				$commentThread = $disqus->getThread($postURL, false);
			}
			if($commentThread){
				$getPosts[$key]['commentCount'] = $commentThread['thread']['posts'];
				$this->model->edit('blog_posts', $row['postId'], array('commentCheck' => timestamp(), 'commentCount' => $commentThread['thread']['posts']));
				$output['totalComments'] += $commentThread['thread']['posts'];
			}
			else{
				$this->model->edit('blog_posts', $row['postId'], array('commentCheck' => timestamp()));
				$output['totalComments'] += $row['commentCount'];
			}
			
			$post['new_comments'] = false;
			$getLastComment = $this->model->fetchSingle('SELECT commentId FROM blog_comments
												  WHERE postId = :postId AND userId != :userId AND editorial = 1
												  ORDER BY commentId DESC',
												array(':postId' => $row['postId'], ':userId' => $this->data['user']['userId']));
												
			if($getLastComment){
				$post['new_comments'] = true;
				if($viewedComments){
					foreach($viewedComments as $viewed){
						$expViewed = explode(':', $viewed);
						if($expViewed[0] == $row['postId']){
							if($expViewed[1] == $getLastComment['commentId']){
								$post['new_comments'] = false;
							}
						}
					}
				}
			}
			$getPosts[$key]['new_comments'] = $post['new_comments'];		
			if($row['userId'] != $this->data['user']['userId']){
				$output['totalContributed']++;
			}
		}
		$output['postList'] = $getPosts;
		
		$output['submission_fee'] = $this->blogSettings['submission-fee'];
		$getDeposit = $this->meta->getUserMeta($this->user['userId'], 'article-credit-deposit-address');
		if(!$getDeposit){
			$btc = new Slick_API_Bitcoin(BTC_CONNECT);
			$accountName = XCP_PREFIX.'BLOG_CREDITS_'.$this->user['userId'];
			try{
				$getAddress = $btc->getaccountaddress($accountName);
			}
			catch(Exception $e){
				$getAddress = false;
			}
			$this->meta->updateUserMeta($this->user['userId'], 'article-credit-deposit-address', $getAddress);
			$output['credit_address'] = $getAddress;
		}
		else{
			$output['credit_address'] = $getDeposit;
		}
		$output['num_credits'] = intval($this->meta->getUserMeta($this->user['userId'], 'article-credits'));
		$output['fee_asset'] = strtoupper($this->blogSettings['submission-fee-token']);
		
		$output['trashCount'] = $this->model->countTrashItems($this->user['userId']);
		$output['trashMode'] = $trash;
		
		
		return $output;
	}
	
	
	private function addPost()
	{
		$output = array('view' => 'form');
		if(!$this->data['perms']['canWritePost']){
			$output['view'] = '403';
			return $output;
		}
		
		$output['num_credits'] = intval($this->meta->getUserMeta($this->user['userId'], 'article-credits'));
		if(!$this->data['perms']['canBypassSubmitFee'] AND $output['num_credits'] <= 0){
			Slick_Util_Session::flash('blog-message', 'You do not have enough submission credits to create a new post', 'error');
			$this->redirect($this->site.$this->moduleUrl);
			die();
		}
		$this->data['user']['perms'] = $this->data['perms'];
		$output['form'] = $this->model->getPostForm(0, $this->data['site']['siteId'], true, $this->data['user']);
		$output['formType'] = 'Submit';

		if(!$this->data['perms']['canPublishPost']){
			$output['form']->field('status')->removeOption('published');
			$output['form']->remove('featured');
		}

		if(isset($this->data['perms']['canUseMagicWords']) AND !$this->data['perms']['canUseMagicWords']){
			$getField = $this->model->get('blog_postMetaTypes', 'magic-word', array(), 'slug');
			if($getField){
				$output['form']->remove('meta_'.$getField['metaTypeId']);
			}
		}
	
		if(!$this->data['perms']['canChangeAuthor']){
			$output['form']->remove('userId');
		}
		else{
			$output['form']->setValues(array('userId' => $this->data['user']['userId']));
		}

		if(posted()){
			$data = $output['form']->grabData();
			if(isset($data['publishDate'])){
				$data['publishDate'] = date('Y-m-d H:i:s', strtotime($data['publishDate']));
			}			
			$data['siteId'] = $this->data['site']['siteId'];
			if(!$this->data['perms']['canChangeAuthor']){
				$data['userId'] = $this->user['userId'];
			}
			if(!$this->data['perms']['canPublishPost']){
				if(isset($data['published'])){
					unset($data['published']);
				}
				if(isset($data['featured'])){
					unset($data['featured']);
				}
				if(isset($data['status']) AND $data['status'] == 'published'){
					$data['status'] = 'draft';
				}
			}
		
			if($data['autogen-excerpt'] == 0){
				$data['excerpt'] = shortenMsg(strip_tags($data['content']), 500);
			}			
			try{
				$add = $this->model->addPost($data, $this->data);
			}
			catch(Exception $e){
				Slick_Util_Session::flash('blog-message', $e->getMessage(), 'error');
				$add = false;
			}
			
			if($add){
				if(!$this->data['perms']['canBypassSubmitFee']){
					//deduct from their current credits
					$newCredits = $output['num_credits'] - 1;
					$this->meta->updateUserMeta($this->user['userId'], 'article-credits', $newCredits);
				}
				
				$this->redirect($this->site.$this->moduleUrl);
			}
			else{
				$this->redirect($this->site.$this->moduleUrl.'/add');
			}
			
			return;
		}
		
		$output['form']->field('publishDate')->setValue(date('Y/m/d H:i'));
		
		return $output;
		
	}
	
	protected function accessPost()
	{
		if(!isset($this->args[3])){
			throw new Exception('404');
		}		
		
		$getPost = $this->model->get('blog_posts', $this->args[3]);
		if(!$getPost OR $getPost['trash'] == 1){
			throw new Exception('404');
		}
		$getPost['published'] = $this->model->checkPostApproved($getPost['postId']);


		$tca = new Slick_App_LTBcoin_TCA_Model;
		$postModule = $tca->get('modules', 'blog-post', array(), 'slug');
		$catModule = $tca->get('modules', 'blog-category', array(), 'slug');	
		$this->data['perms'] = $tca->checkPerms($this->data['user'], $this->data['perms'], $postModule['moduleId'], $getPost['postId'], 'blog-post');
		$foundRole = true;
		$getPost['user_blog_role'] = false;
		$getPost['pending_contrib'] = false;
		$getPost['active_contrib'] = false;
		
		if(!$this->data['perms']['canManageAllBlogs']){
			$postTCA = $tca->checkItemAccess($this->data['user'], $postModule['moduleId'], $getPost['postId'], 'blog-post');
			if(!$postTCA){
				throw new Exception('403');
			}
			
			if($getPost['userId'] != $this->data['user']['userId']){
				$foundBlogRole = $this->model->checkPostBlogRole($getPost['postId'], $this->data['user']['userId']);
				$foundRole = $foundBlogRole;

				$getContribs = $this->model->getPostContributors($getPost['postId'], false);
				foreach($getContribs as $contrib){
					if($contrib['userId'] == $this->data['user']['userId']){
						$foundRole = true;
						if($contrib['accepted'] == 0){
							$getPost['pending_contrib'] = true;
						}
						else{
							$getPost['active_contrib'] = true;
						}
					}
				}
				$getPost['contributors'] = $getContribs;
				
				if($foundBlogRole){
					$getPost['user_blog_role'] = true;
				}
			}
			
			if(($getPost['userId'] != $this->data['user']['userId'] AND !$foundRole AND !$this->data['perms']['canEditOtherPost'])
				OR
			   ($getPost['userId'] == $this->data['user']['userId'] AND !$this->data['perms']['canEditSelfPost'])
			   ){
				throw new Exception('403');
			}
			
			if($getPost['status'] == 'published' AND !$this->data['perms']['canEditAfterPublished']){
				throw new Exception('403');
			}
		}
		
		$getPost['categories'] = $this->model->getPostFormCategories($getPost['postId']);
		$getPost['author'] = $this->model->get('users', $getPost['userId']);
		
		return $getPost;
	}
	
	protected function editPost()
	{
		try{
			$getPost = $this->accessPost();
		}
		catch(Exception $e){
			return array('view' => $e->getMessage());
		}
		
		$output = array('view' => 'form');
		$this->data['user']['perms'] = $this->data['perms'];
		$output['form'] = $this->model->getPostForm($getPost['postId'], $this->data['site']['siteId'], true, $this->data['user']);
		$output['formType'] = 'Edit';
		$output['post'] = $getPost;
		$this->data['post'] = $getPost;
		$output['unlock_post'] = true;
		$contributor = $this->model->checkUserContributor($getPost['postId'], $this->data['user']['userId']);
		$output['contributor'] = $contributor;
		$output['contributor_list'] = $this->model->getPostContributors($getPost['postId'], false);

		if($getPost['userId'] != $this->data['user']['userId'] AND !$this->data['perms']['canManageAllBlogs']){
			if((!$contributor OR $getPost['status'] == 'published')){
				if(!$contributor OR !$getPost['user_blog_role']){
					$output['form']->field('title')->addAttribute('disabled');
					$output['form']->field('url')->addAttribute('disabled');
					$output['form']->field('formatType')->addAttribute('disabled');
					$output['form']->field('content')->addAttribute('disabled');
					$output['form']->field('excerpt')->addAttribute('disabled');
					$output['form']->field('autogen-excerpt')->addAttribute('disabled');
					$output['form']->field('notes')->addAttribute('disabled');
					$output['form']->field('coverImage')->addAttribute('disabled');
					$output['form']->field('status')->addAttribute('disabled');
					$output['form']->field('publishDate')->addAttribute('disabled');					
					$output['unlock_post'] = false;
				}

			}
			//still disable some stuff for them
			if(!$getPost['user_blog_role']){
				$output['form']->field('status')->addAttribute('disabled');
				$output['form']->field('publishDate')->addAttribute('disabled');	
				$output['form']->field('coverImage')->addAttribute('disabled');
			}		
			$output['form']->field('categories')->addAttribute('disabled');
			foreach($output['form']->fields as $fkey => $field){
				if(strpos($fkey, 'meta_') === 0){
					$output['form']->field($fkey)->addAttribute('disabled');
				}
			}
		}
		
		if(isset($this->data['perms']['canUseMagicWords'])){
			if(!$this->data['perms']['canUseMagicWords']){
				$getField = $this->model->get('blog_postMetaTypes', 'magic-word', array(), 'slug');
				if($getField){
					$output['form']->remove('meta_'.$getField['metaTypeId']);
				}
			}
			else{
				$getWords = $this->model->getAll('pop_words', array('itemId' => $getPost['postId'],
																	'moduleId' => $this->postModule['moduleId']),
																array('submitId'));
				$output['magic_word_count'] = count($getWords);
			}
		}
		
		if(!$this->data['perms']['canPublishPost']){
			if($getPost['status'] == 'published'){
				$output['form']->field('status')->addAttribute('disabled');
			}
			else{
				$output['form']->field('status')->removeOption('published');
			}
			$output['form']->remove('featured');
		}

		if(!$this->data['perms']['canChangeAuthor']){
			$output['form']->remove('userId');
		}
		
		if($getPost['published'] == 1){
			$getPost['status'] = 'published';
		}
		elseif($getPost['ready'] == 1){
			$getPost['status'] = 'ready';
		}
		
		//request/invite a contributor
		if(posted()){
			if(isset($_POST['request-contrib']) AND !$contributor){
				return $this->requestContributor($output);
			}
			elseif(isset($_POST['invite-contrib']) AND ($this->data['user']['userId'] == $getPost['userId'] OR $this->data['perms']['canManageAllBlogs'])){
				return $this->requestContributor($output, true);
			}
			elseif(isset($_POST['update-contribs']) AND ($this->data['user']['userId'] == $getPost['userId'] OR $this->data['perms']['canManageAllBlogs'])){
				return $this->updateContributors($output);
			}
		}
		//contributor controls
		if(isset($this->args[4]) AND $this->args[4] == 'contributors'){
			if(isset($this->args[5])){
				switch($this->args[5]){
					case 'delete':
						return $this->deleteContributor($output);
				}
			}	
		}	
		
		
		if(posted() AND !isset($_POST['no_edit']) AND $output['unlock_post']){
			$data = $output['form']->grabData();
			if(isset($data['publishDate'])){
				$data['publishDate'] = date('Y-m-d H:i:s', strtotime($data['publishDate']));
			}
			if($getPost['userId'] != $this->data['user']['userId'] AND !$getPost['user_blog_role']){
				$data['publishDate'] = $getPost['publishDate'];
				$data['status'] = $getPost['status'];
			}

			$data['siteId'] = $this->data['site']['siteId'];
			if(!$this->data['perms']['canChangeAuthor']){
				$data['userId'] = false;
			}

			if(!$this->data['perms']['canPublishPost']){
				if($getPost['published'] == 0){
					if(isset($data['status']) AND $data['status'] == 'published'){
						$data['status'] = 'draft';
					}
				}
				else{
					$data['status'] = 'published';
				}
			}

			if(!isset($data['status'])){ 
				$data['status'] = $getPost['status'];
			}	

			if($data['autogen-excerpt'] == 0){
				$data['excerpt'] = shortenMsg(strip_tags($data['content']), 500);
			}
			$data['contributor'] = $contributor;
			try{
				$edit = $this->model->editPost($this->args[3], $data, $this->data);
			}
			catch(Exception $e){
				Slick_Util_Session::flash('blog-message', $e->getMessage(), 'error');			
				$edit = false;
			}
			
			if($edit){
				Slick_Util_Session::flash('blog-message', 'Post edited successfully!', 'success');
			}
			$this->redirect($this->site.'/'.$this->data['app']['url'].'/'.$this->data['module']['url'].'/edit/'.$getPost['postId']);
			return true;
		}	
		
		//get version list and #
		$output['versions'] = $this->model->getVersions($getPost['postId']);
		$output['current_version'] = $this->model->getVersionNum($getPost['postId']);
		$output['old_version'] = false;
		
		if(isset($this->args[4])){
			foreach($output['versions'] as $version){
				if($version['num'] == $this->args[4]){
					$oldVersion = $this->model->getPostVersion($getPost['postId'], $version['num']);
					if($oldVersion AND $oldVersion['versionId'] != $getPost['version']){
						if(isset($this->args[5]) AND $this->args[5] == 'delete'){
							if(($getPost['userId'] == $this->data['user']['userId'] AND $this->data['perms']['canDeleteSelfPostVersion'])
								OR
							 ($getPost['userId'] != $this->data['user']['userId'] AND $this->data['perms']['canDeleteOtherPostVersion'])){
								$killVersion = $this->model->delete('content_versions', $oldVersion['versionId']);
								Slick_Util_Session::flash('blog-message', 'Version #'.$oldVersion['num'].' removed', 'success');
								$this->redirect($this->site.'/'.$this->data['app']['url'].'/'.$this->data['module']['url'].'/edit/'.$getPost['postId']);
								die();
							}
						}
						$output['post']['content'] = $oldVersion['content']['content'];
						$output['post']['excerpt'] = $oldVersion['content']['excerpt'];
						$output['old_version'] = $oldVersion;
						$getPost['content'] = $output['post']['content'];
						$getPost['excerpt'] = $output['post']['excerpt'];
						$output['post']['formatType'] = $oldVersion['formatType'];
						$getPost['formatType'] = $oldVersion['formatType'];
						if($oldVersion['formatType'] == 'wysiwyg'){
							$output['form']->field('content')->setLivePreview(false);
							$output['form']->field('content')->setID('html-editor');
							$output['form']->field('excerpt')->setLivePreview(false);
							$output['form']->field('excerpt')->setID('mini-editor');							
						}
					}
					break;
				}
			}
		}		
		
		//private editorial discussion
		$output['comment_form'] = $this->postModel->getCommentForm();
		$output['private_comments'] = $this->postModel->getPostComments($getPost['postId'], 1);
		if(count($output['private_comments']) > 0){
			$meta = new Slick_App_Meta_Model;
			$viewedComments = $meta->getUserMeta($this->data['user']['userId'], 'viewed-editorial-comments');
			$getLastComment = $meta->fetchSingle('SELECT commentId FROM blog_comments
												  WHERE postId = :postId AND userId != :userId AND editorial = 1
												  ORDER BY commentId DESC',
												array(':postId' => $getPost['postId'], ':userId' => $this->data['user']['userId']));			
			if($viewedComments){
				$viewedComments = explode(',', $viewedComments);
			}
			else{
				$viewedComments = array();
			}
		
			$updateViewed = true;
			foreach($viewedComments as $k => $viewed){
				$expViewed = explode(':', $viewed);
				if($expViewed[0] == $getPost['postId']){
					if($expViewed[1] == $getLastComment['commentId']){
						$updateViewed = false;
					}
					else{
						unset($viewedComments[$k]);
						$updateViewed = true;
						break;
					}
				}
			}
			if($updateViewed){
				$viewedComments[] = $getPost['postId'].':'.$getLastComment['commentId'];
				$meta->updateUserMeta($this->data['user']['userId'], 'viewed-editorial-comments', join(',',$viewedComments));
			}
		}

		$output['comment_list_hash'] = $this->model->getCommentListHash($getPost['postId']);
		if(isset($this->args[4]) AND $this->args[4] == 'comments'){
			if(isset($this->args[5])){
				switch($this->args[5]){
					case 'post':
						$json = $this->postPrivateComment();
						break;
					case 'edit':
						$json = $this->editPrivateComment();
						break;
					case 'delete':
						$json = $this->deletePrivateComment();
						break;
					case 'check':
						$json = $this->checkCommentList();
						break;
					case 'get':
					default:
						$json = $this->getPrivateComments();
						break;
				}
				
				ob_end_clean();
				header('Content-Type: application/json');
				echo json_encode($json);
				die();
			}
		}
		
		
		//setup form values
		$catOpts = $output['form']->field('categories')->getOptions();
		foreach($getPost['categories'] as $catId){
			$catOpts = $this->model->parseApprovedCategoryOptions($catOpts, $getPost['postId'], $catId);
		}
		$output['form']->field('categories')->setOptions($catOpts);
		$output['form']->setValues($getPost);
		$output['form']->field('publishDate')->setValue(date('Y/m/d H:i', strtotime($getPost['publishDate'])));
		
		return $output;
	}
	
	
	protected function postPrivateComment()
	{
		$output = array('error' => null);
		
		if(!posted()){
			http_response_code(400);
			$output['error'] = 'Invalid request method';
			return $output;
		}
		
		if(!$this->data['perms']['canPostComment']){
			http_response_code(403);
			$output['error'] = 'You do not have permission for this';
			return $output;
		}
		
		if(!isset($_POST['message'])){
			http_response_code(400);
			$output['error'] = 'Message required';
			return $output;
		}
		
		$data = array();
		$data['postId'] = $this->data['post']['postId'];
		$data['userId'] = $this->data['user']['userId'];
		$data['message'] = strip_tags($_POST['message']);
		
		try{
			$postComment = $this->postModel->postComment($data, $this->data, 1);
		}
		catch(Exception $e){
			http_response_code(400);
			$output['error'] = $e->getMessage();
			return $output;
		}
		
		$output['result'] = 'success';
		$postComment['formatDate'] = formatDate($postComment['commentDate']);
		$postComment['html_content'] = markdown($postComment['message']);
		$postComment['encoded'] = base64_encode($postComment['message']);
		$profModel = new Slick_App_Profile_User_Model;
		$authProf = $profModel->getUserProfile($postComment['userId']);
		$postComment['author'] = array('username' => $authProf['username'], 'slug' => $authProf['slug'], 'avatar' => $authProf['avatar']);
		$output['comment'] = $postComment;
		$output['new_hash'] = $this->model->getCommentListHash($this->data['post']['postId']);
		
		return $output;
		
	}
	
	protected function deletePrivateComment()
	{
		$output = array('error' => null);
		
		if(!posted()){
			http_response_code(400);
			$output['error'] = 'Invalid request method';
			return $output;
		}	
		
		if(!isset($_POST['commentId'])){
			http_response_code(400);
			$output['error'] = 'Comment ID required';
			return $output;
		}
		
		$comment = $this->model->get('blog_comments', $_POST['commentId']);
		if(!$comment){
			http_response_code(400);
			$output['error'] = 'Invalid comment ID';
			return $output;
		}
		
		if(($comment['userId'] == $this->data['user']['userId'] AND !$this->data['perms']['canDeleteSelfComment'])
			OR ($comment['userId'] != $this->data['user']['userId'] AND !$this->data['perms']['canDeleteOtherComment'])){
			http_response_code(403);
			$output['error'] = 'You do not have permission for this';
			return $output;
		}			
		
		$delete = $this->model->delete('blog_comments', $comment['commentId']);
		$output['result'] = 'success';
	
		return $output;
	}
	
	protected function editPrivateComment()
	{
		$output = array('error' => null);
		
		if(!posted()){
			http_response_code(400);
			$output['error'] = 'Invalid request method';
			return $output;
		}	
		
		if(!isset($_POST['commentId'])){
			http_response_code(400);
			$output['error'] = 'Comment ID required';
			return $output;
		}
		
		if(!isset($_POST['message'])){
			http_response_code(400);
			$output['error'] = 'Message';
			return $output;
		}
		
		$comment = $this->model->get('blog_comments', $_POST['commentId']);
		if(!$comment){
			http_response_code(400);
			$output['error'] = 'Invalid comment ID';
			return $output;
		}
		
		if(($comment['userId'] == $this->data['user']['userId'] AND !$this->data['perms']['canEditSelfComment'])
			OR ($comment['userId'] != $this->data['user']['userId'] AND !$this->data['perms']['canEditOtherComment'])){
			http_response_code(403);
			$output['error'] = 'You do not have permission for this';
			return $output;
		}
		
		$data = array();
		$data['message'] = strip_tags($_POST['message']);
		$data['editTime'] = timestamp();
		
		$edit = $this->model->edit('blog_comments', $comment['commentId'], $data);

		$output['result'] = 'success';
		$comment['formatDate'] = formatDate($comment['commentDate']);
		$comment['formatEditDate'] = formatDate($data['editTime']);
		$comment['html_content'] = markdown($data['message']);
		$comment['encoded'] = base64_encode($data['message']);
		$profModel = new Slick_App_Profile_User_Model;
		$authProf = $profModel->getUserProfile($comment['userId']);
		$comment['author'] = array('username' => $authProf['username'], 'slug' => $authProf['slug'], 'avatar' => $authProf['avatar']);
		$output['comment'] = $comment;
		$output['new_hash'] = $this->model->getCommentListHash($this->data['post']['postId']);
		
		return $output;
	}
	
	protected function checkCommentList()
	{
		$hash = $this->model->getCommentListHash($this->data['post']['postId']);
		return array('hash' => $hash);
	}
	
	protected function getPrivateComments()
	{
		$comments = $this->postModel->getPostComments($this->data['post']['postId'], 1);
		foreach($comments as &$comment){
			$comment['author'] = array('username' => $comment['author']['username'],
									   'slug' => $comment['author']['slug'],
									   'avatar' => $comment['author']['avatar']);
			$comment['html_content'] = markdown($comment['message']);
			$comment['encoded'] = base64_encode($comment['message']);
			$comment['formatDate'] = formatDate($comment['commentDate']);
			$comment['formatEditDate'] = formatDate($comment['editTime']);
			unset($comment['buried']);
			unset($comment['editorial']);
			unset($comment['postId']);
			
		}
		$output['comments'] = $comments;
		$output['new_hash'] = $this->model->getCommentListHash($this->data['post']['postId']);
		
		return $output;
	}

	
	private function deletePost()
	{
		if(!isset($this->args[3])){
			$this->redirect($this->site.$this->moduleUrl);
			return false;
		}
		
		$getPost = $this->model->get('blog_posts', $this->args[3]);
		if(!$getPost){
			$this->redirect($this->site.$this->moduleUrl);
			return false;
		}
		
		if(($getPost['userId'] == $this->data['user']['userId'] AND !$this->data['perms']['canDeleteSelfPost'])
		OR ($getPost['userId'] != $this->data['user']['userId'] AND !$this->data['perms']['canDeleteOtherPost'])){
			return array('view' => '403');
		}

		if($getPost['published'] == 1 AND !$this->data['perms']['canPublishPost']){
			return array('view' => '403');
		}
		
		$tca = new Slick_App_LTBcoin_TCA_Model;
		$postModule = $tca->get('modules', 'blog-post', array(), 'slug');
		$catModule = $tca->get('modules', 'blog-category', array(), 'slug');
		$postTCA = $tca->checkItemAccess($this->data['user'], $postModule['moduleId'], $getPost['postId'], 'blog-post');
		if(!$postTCA){
			return array('view' => '403');
		}
		$getCategories = $this->model->getAll('blog_postCategories', array('postId' => $getPost['postId']));
		foreach($getCategories as $cat){
			$catTCA = $tca->checkItemAccess($this->data['user'], $catModule['moduleId'], $cat['categoryId'], 'blog-category');
			if(!$catTCA){
				return array('view' => '403');
			}
		}			
		
		$delete = $this->model->delete('blog_posts', $this->args[3]);
		Slick_Util_Session::flash('blog-message', $getPost['title'].' deleted successfully', 'success');
		
		$this->redirect($this->site.$this->moduleUrl.'/trash');
		return true;
	}
	
	private function previewPost($output)
	{
		if(!isset($this->args[3])){
			$this->redirect($this->site.$this->moduleUrl);
			return false;
		}
		
		$model = new Slick_App_Blog_Post_Model;
		$getPost = $model->getPost($this->args[3], $this->data['site']['siteId']);
		if(!$getPost){
			$this->redirect($this->site.$this->moduleUrl);
			return false;
		}
		
		if(isset($this->args[4])){
			$oldVersion = $this->model->getPostVersion($getPost['postId'], $this->args[4]);
			if($oldVersion){
				$getPost['content'] = $oldVersion['content']['content'];
				$getPost['excerpt'] = $oldVersion['content']['excerpt'];
			}
		}	
		
		$tca = new Slick_App_LTBcoin_TCA_Model;
		$postModule = $tca->get('modules', 'blog-post', array(), 'slug');
		$catModule = $tca->get('modules', 'blog-category', array(), 'slug');
		$this->data['perms'] = $tca->checkPerms($this->data['user'], $this->data['perms'], $postModule['moduleId'], $getPost['postId'], 'blog-post');
		$postTCA = $tca->checkItemAccess($this->data['user'], $postModule['moduleId'], $getPost['postId'], 'blog-post');
		if(!$postTCA){
			return array('view' => '403');
		}
		$getCategories = $this->model->getAll('blog_postCategories', array('postId' => $getPost['postId']));
		foreach($getCategories as $cat){
			$catTCA = $tca->checkItemAccess($this->data['user'], $catModule['moduleId'], $cat['categoryId'], 'blog-category');
			if(!$catTCA){
				return array('view' => '403');
			}
		}				
		
		$cats = array();
		foreach($getCategories as $cat){
			$getCat = $this->model->get('blog_categories', $cat['categoryId']);
			$cats[] = $getCat;
		}
		$getPost['categories'] = $cats;
		
		$output['template'] = 'blog';
		$output['view'] = '';
		$output['force-view'] = 'Blog/Post/post';
		$output['post'] = $getPost;
		$output['disableComments'] = true;
		$output['user'] = Slick_App_Account_Home_Model::userInfo();
		$output['title'] = $getPost['title'];
		$output['commentError'] = '';
		$output['comments'] = array();
		

		return $output;
		
	}
	
	
	protected function checkCreditPayment()
	{
		ob_end_clean();
		header('Content-Type: application/json');		
		$output = array('result' => null, 'error' => null);
		if(isset($_SESSION['blog-credit-check-progress'])){
			unset($_SESSION['blog-credit-check-progress']);
			echo json_encode($output);
			die();
		}
		$_SESSION['blog-credit-check-progress'] = 1;
		
		//get latest deposit address
		$getAddress = $this->meta->getUserMeta($this->user['userId'], 'article-credit-deposit-address');
		if(!$getAddress){
			http_response_code(400);
			$output['error'] = 'No deposit address found';
		}
		else{
			//check balances including the mempool
			$assetInfo = $this->inventory->getAssetData($this->blogSettings['submission-fee-token']);
			$xcp = new Slick_API_Bitcoin(XCP_CONNECT);
			$btc = new Slick_API_Bitcoin(BTC_CONNECT);
			try{
				$getPool = $xcp->get_mempool();
				$getBalances = $xcp->get_balances(array('filters' => array('field' => 'address', 'op' => '=', 'value' => $getAddress)));
				
				$received = 0;
				$confirmCoin = 0;
				$newCoin = 0;
				foreach($getBalances as $balance){
					if($balance['asset'] == $assetInfo['asset']){
						$confirmCoin = $balance['quantity'];
						if($assetInfo['divisible'] == 1 AND $confirmCoin > 0){
							$confirmCoin = $confirmCoin / SATOSHI_MOD;
						}
						$received+= $confirmCoin;
					}
				}
				foreach($getPool as $pool){
					if($pool['category'] == 'sends'){
						$parse = json_decode($pool['bindings'], true);
						if($parse['destination'] == $getAddress AND $parse['asset'] == $assetInfo['asset']){
							//check TX to make sure its an actual unconfirmed transaction
							$getTx = $btc->gettransaction($pool['tx_hash']);
							if($getTx AND $getTx['confirmations'] == 0){
								$newCoin = $parse['quantity'];
								if($assetInfo['divisible'] == 1 AND $newCoin > 0){
									$newCoin = $newCoin / SATOSHI_MOD;
								}
								$received+= $newCoin;
							}
						}
					}
				}
			}
			catch(Exception $e){
				http_response_code(400);
				$output['error'] = 'Error retrieving data from xcp server';
			}
			
			//check for previous payment orders on this address, deduct from total seen
			$prevOrders = $this->model->getAll('payment_order', array('address' => $getAddress, 'orderType' => 'blog-submission-credits'));
			$pastOrdered = 0;
			foreach($prevOrders as $prevOrder){
				$prevData = json_decode($prevOrder['orderData'], true);
				$pastOrdered += $prevData['new-received'];
			}
			
			$received -= $pastOrdered;

			//calculate change, number of credits etc.
			$getChange = floatval($this->meta->getUserMeta($this->user['userId'], 'article-credit-deposit-change'));
			$getCredits = intval($this->meta->getUserMeta($this->user['userId'], 'article-credits'));
			$submitFee = intval($this->blogSettings['submission-fee']);
			$origReceived = $received;
			$received += $getChange;
			$leftover = $received % $submitFee;
			$numCredits = floor($received / $submitFee);
			
			//check if enough for at least 1 credit
			if($numCredits > 0){
				
				//save as store order
				$orderData = array();
				$orderData['userId'] = $this->user['userId'];
				$orderData['credits'] = $numCredits;
				$orderData['credit-price'] = $submitFee;
				$orderData['new-received'] = $origReceived;
				$orderData['previous-change'] = $getChange;
				$orderData['leftover-change'] = $leftover;
				
				$order = array();
				$order['address'] = $getAddress;
				$order['account'] = XCP_PREFIX.'BLOG_CREDITS_'.$this->user['userId'];
				$order['amount'] = $numCredits * $submitFee;
				$order['asset'] = $assetInfo['asset'];
				$order['received'] = $origReceived;
				$order['complete'] = 1;
				$order['orderTime'] = timestamp();
				$order['orderType'] = 'blog-submission-credits';
				$order['completeTime'] = $order['orderTime'];
				$order['orderData'] = json_encode($orderData);
				
				$saveOrder = $this->model->insert('payment_order', $order);
				if(!$saveOrder){
					http_response_code(400);
					$output['error'] = 'Error saving payment order';
					echo json_encode($output);
					die();					
				}
				
				//save credits and leftover change
				$newCredits = $getCredits + $numCredits;
				$updateCredits = $this->meta->updateUserMeta($this->user['userId'], 'article-credits', $newCredits);
				$updateChange = $this->meta->updateUserMeta($this->user['userId'], 'article-credit-deposit-change', $leftover);
			
				//setup response data
				$output['result'] = 'success';
				$output['credits'] = $newCredits;
				$output['new_credits'] = $numCredits;
				$output['received'] = $origReceived;
				$output['old_change'] = $getChange;
				$output['new_change'] = $leftover;
			}
			else{
				$output['result'] = 'none';	
			}
		}
		
		ob_end_clean();
		unset($_SESSION['blog-credit-check-progress']);
		echo json_encode($output);
		die();
	}
	
	private function trashPost($restore = false)
	{
		if(!isset($this->args[3])){
			$this->redirect($this->site.$this->moduleUrl);
			return false;
		}
		
		$getPost = $this->model->get('blog_posts', $this->args[3]);
		if(!$getPost){
			$this->redirect($this->site.$this->moduleUrl);
			return false;
		}
		
		if($getPost['userId'] != $this->data['user']['userId']){
			return array('view' => '403');
		}

		if($getPost['published'] == 1 AND !$this->data['perms']['canPublishPost']){
			return array('view' => '403');
		}
		
		$tca = new Slick_App_LTBcoin_TCA_Model;
		$postModule = $tca->get('modules', 'blog-post', array(), 'slug');
		$catModule = $tca->get('modules', 'blog-category', array(), 'slug');
		$postTCA = $tca->checkItemAccess($this->data['user'], $postModule['moduleId'], $getPost['postId'], 'blog-post');
		if(!$postTCA){
			return array('view' => '403');
		}
		$getCategories = $this->model->getAll('blog_postCategories', array('postId' => $getPost['postId']));
		foreach($getCategories as $cat){
			$catTCA = $tca->checkItemAccess($this->data['user'], $catModule['moduleId'], $cat['categoryId'], 'blog-category');
			if(!$catTCA){
				return array('view' => '403');
			}
		}			
		
		if($restore){
			$restorePost = $this->model->edit('blog_posts', $this->args[3], array('trash' => 0));
			Slick_Util_Session::flash('blog-message', $getPost['title'].' restored from trash', 'success');
			$this->redirect($this->site.$this->moduleUrl.'/trash');
		}
		else{
			$delete = $this->model->edit('blog_posts', $this->args[3], array('trash' => 1));
			Slick_Util_Session::flash('blog-message', $getPost['title'].' moved to trash', 'success');
			$this->redirect($this->site.$this->moduleUrl);
		}
		return true;
	}		
		
	private function clearTrash()
	{

		$trashPosts = $this->model->getAll('blog_posts', array('siteId' => $this->data['site']['siteId'],
															 'userId' => $this->user['userId'], 
															 'trash' => 1));
															 
		$tca = new Slick_App_LTBcoin_TCA_Model;
		$postModule = $tca->get('modules', 'blog-post', array(), 'slug');
		$catModule = $tca->get('modules', 'blog-category', array(), 'slug');															 
		
		foreach($trashPosts as $getPost){
			if(($getPost['userId'] == $this->data['user']['userId'] AND !$this->data['perms']['canDeleteSelfPost'])
			OR ($getPost['userId'] != $this->data['user']['userId'] AND !$this->data['perms']['canDeleteOtherPost'])){
				return array('view' => '403');
			}

			if($getPost['published'] == 1 AND !$this->data['perms']['canPublishPost']){
				return array('view' => '403');
			}
			
			$postTCA = $tca->checkItemAccess($this->data['user'], $postModule['moduleId'], $getPost['postId'], 'blog-post');
			if(!$postTCA){
				return array('view' => '403');
			}
			$getCategories = $this->model->getAll('blog_postCategories', array('postId' => $getPost['postId']));
			foreach($getCategories as $cat){
				$catTCA = $tca->checkItemAccess($this->data['user'], $catModule['moduleId'], $cat['categoryId'], 'blog-category');
				if(!$catTCA){
					return array('view' => '403');
				}
			}			
			
			$delete = $this->model->delete('blog_posts', $getPost['postId']);
		}
		
		Slick_Util_Session::flash('blog-message', 'Trash bin emptied!', 'success');
		$this->redirect($this->site.$this->moduleUrl.'/trash');
	
		return true;
	}		
	
	public function comparePostVersions()
	{
		try{
			$getPost = $this->accessPost();
		}
		catch(Exception $e){
			return array('view' => $e->getMessage());
		}
		
		$v1 = false;
		$v2 = false;
		if(isset($this->args[4])){
			$v1 = intval($this->args[4]);
		}
		if(isset($this->args[4])){
			$v2 = intval($this->args[5]);
		}
		
		$compare = $this->model->comparePostVersions($getPost['postId'], $v1, $v2);
		$compare['v1_user'] = array('userId' => $compare['v1_user']['userId'], 'username' => $compare['v1_user']['username'], 'slug' => $compare['v1_user']['slug']);
		$compare['v2_user'] = array('userId' => $compare['v2_user']['userId'], 'username' => $compare['v2_user']['username'], 'slug' => $compare['v2_user']['slug']);
		
		ob_end_clean();
		header('Content-Type: application/json');
		$output = $compare;
		
		echo json_encode($output);
		die();
	}	
	
	public function requestContributor($output, $author_invite = false)
	{
		$redirect_link = $this->site.'/'.$this->data['app']['url'].'/'.$this->data['module']['url'].'/edit/'.$output['post']['postId'];

		if($author_invite){
			$getUser = $this->model->get('users', trim($_POST['username']), array('userId', 'username', 'slug', 'email'), 'username');
			if(!$getUser OR $getUser['userId'] == $output['post']['userId']){
				Slick_Util_Session::flash('blog-message', 'User '.$_POST['username'].' not found (contributor request)', 'error');
				$this->redirect($redirect_link);
				die();
			}
		}
			
		$role = strip_tags($_POST['role']);
		if(trim($role) == ''){
			Slick_Util_Session::flash('blog-message', 'Must enter a contributor role', 'error');
			$this->redirect($redirect_link);
			die();
		}
		
		$share = round(floatval($_POST['share']), 2);
		
		$contribs = $this->model->getPostContributors($output['post']['postId'], false);
		$totalShare = $share;
		foreach($contribs as $contrib){
			if(!$author_invite){
				if($this->data['user']['userId'] == $contrib['userId']){
					Slick_Util_Session::flash('blog-message', 'You already have a pending contribution request', 'error');				
					$this->redirect($redirect_link);
					die();
				}
			}
			else{
				if($getUser['userId'] == $contrib['userId']){
					Slick_Util_Session::flash('blog-message', 'User already pending contribution request', 'error');
					$this->redirect($redirect_link);
					die();
				}
			}
			$totalShare += $contrib['share'];
		}
		
		if($share < 0){
			Slick_Util_Session::flash('blog-message', 'Reward share cannot be less than 0', 'error');
			$this->redirect($redirect_link);
			die();
		}
		
		if($totalShare > 100){
			Slick_Util_Session::flash('blog-message', 'Total reward share percentage cannot go over 100%', 'error');
			$this->redirect($redirect_link);
			die();
		}
		
		if(!$author_invite){
			$inviteData = array('userId' => $this->data['user']['userId'], 'acceptUser' => $output['post']['userId'], 'sendUser' => $this->data['user']['userId'],
						  'type' => 'blog_contributor', 'itemId' => $output['post']['postId'], 'info' => array('request_type' => 'request',
						  'post_title' => $output['post']['title'], 'request_role' => $role, 'request_share' => $share),
						  'class' => 'Slick_App_Dashboard_Blog_Submissions_Model');	
		}
		else{
			$inviteData = array('userId' => $getUser['userId'], 'acceptUser' => $getUser['userId'], 'sendUser' => $this->data['user']['userId'],
						  'type' => 'blog_contributor', 'itemId' => $output['post']['postId'], 'info' => array('request_type' => 'invite',
						  'post_title' => $output['post']['title'], 'request_role' => $role, 'request_share' => $share),
						  'class' => 'Slick_App_Dashboard_Blog_Submissions_Model');	
		}
		
		$invite = $this->invite->sendInvite($inviteData);
		$contribData = array('postId' => $output['post']['postId'], 'inviteId' => $invite['inviteId'],
							'role' => $role, 'share' => $share);
		
		$add_contrib = $this->model->insert('blog_contributors', $contribData);
		Slick_Util_Session::flash('blog-message', 'Contributor request sent!', 'success');
		$this->redirect($redirect_link);
		die();
	}
	
	public function deleteContributor($output)
	{
		$redirect_link = $this->site.'/'.$this->data['app']['url'].'/'.$this->data['module']['url'].'/edit/'.$output['post']['postId'];
		$getContrib = $this->model->get('blog_contributors', @$this->args[6]);
		if(!$getContrib){
			$output['view'] = '404';
			return $output;
		}
		
		if($getContrib['postId'] != $output['post']['postId']){
			$output['view'] = '403';
			return $output;
		}
		
		$getInvite = $this->model->get('user_invites', $getContrib['inviteId']);
		$getUser = $this->model->get('users', $getInvite['userId'], array('userId', 'username', 'slug'));
		
		if(($getInvite['accepted'] == 0 AND $output['post']['userId'] == $this->data['user']['userId'])
			OR $this->data['perms']['canManageAllBlogs']
			OR ($getInvite['userId'] == $this->data['user']['userId'])){
			$delete = $this->model->delete('user_invites', $getContrib['inviteId']);
			if($delete){
				if($getInvite['accepted'] == 1){
					$notifyData = array();
					$notifyData['quitter'] = $getUser;
					$notifyData['post'] = $output['post'];
					$this->model->notifyContributors($output['post']['postId'], 'contributor_quit', $notifyData, 0);
				}
				Slick_Util_Session::flash('blog-message', $getUser['username'].' has been removed as a contributor.', 'success');
				$this->redirect($redirect_link);
				die();			
			}
		}
		$output['view'] = '403';
		return $output;
	}
	
	public function updateContributors($output)
	{
		$redirect_link = $this->site.'/'.$this->data['app']['url'].'/'.$this->data['module']['url'].'/edit/'.$output['post']['postId'];
		
		$changeRoles = false;
		$changeShares = false;
		
		if($output['post']['userId'] == $this->data['user']['userId']){
			$changeRoles = true;
		}
		
		if($this->data['perms']['canManageAllBlogs']){
			$changeRoles = true;
			$changeShares = true;
		}
		
		if(!$changeRoles AND !$changeShares){
			$output['view'] = '403';
			return $output;
		}
		
		$updateList = array();
		foreach($_POST as $k => $v){
			$exp = explode('_', $k);
			if(!isset($exp[1])){
				continue;
			}
			$itemId = false;
			if(($exp[0] == 'role' AND $changeRoles) OR ($exp[0] == 'share' AND $changeShares)){
				$itemId = intval($exp[1]);
				$getContrib = $this->model->get('blog_contributors', $itemId);
				if(!$getContrib){
					continue;
				}
			}
			
			if($itemId){
				if(!isset($updateList[$itemId])){
					$updateList[$itemId] = array();
				}
				$updateList[$itemId][$exp[0]] = $v;
			}
		}
		
		foreach($updateList as $itemId => $item){
			if(isset($item['share'])){
				$item['share'] = floatval($item['share']);
			}
			if(isset($item['role'])){
				$item['role'] = strip_tags($item['role']);
			}
			$edit = $this->model->edit('blog_contributors', $itemId, $item);
		}
		
		Slick_Util_Session::flash('blog-message', 'Contributor list updated!', 'success');
		$this->redirect($redirect_link);	
		die();
	}
	

}
