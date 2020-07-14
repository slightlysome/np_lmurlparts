<?php
/*
    LMFancierURL Nucleus plugin
    Copyright (C) 2011-2013 Leo (www.slightlysome.net)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.
	(http://www.gnu.org/licenses/gpl-2.0.html)
	
	See lmurlparts/help.html for plugin description, install, usage and change history.
*/
class NP_LMURLParts extends NucleusPlugin
{
	// name of plugin
	function getName()
	{
		return 'LMURLParts';
	}

	// author of plugin
	function getAuthor()
	{
		return 'Leo (www.slightlysome.net)';
	}

	// an URL to the plugin website
	// can also be of the form mailto:foo@bar.com
	function getURL()
	{
		return 'http://www.slightlysome.net/nucleus-plugins/np_lmurlparts';
	}

	// version of the plugin
	function getVersion()
	{
		return '1.1.2';
	}

	// a description to be shown on the installed plugins listing
	function getDescription()
	{
		return 'The NP_LMURLParts plugin is a general helper plugin used to keep track of URL parts.';
	}

	function supportsFeature ($what)
	{
		switch ($what)
		{
			case 'SqlTablePrefix':
				return 1;
			case 'SqlApi':
				return 1;
			case 'HelpPage':
				return 1;
			default:
				return 0;
		}
	}
	
	function hasAdminArea()
	{
		return 1;
	}
	
	function getMinNucleusVersion()
	{
		return '350';
	}
	
	function getTableList()
	{	
		return 	array($this->getTableURLPart(), $this->getTableType());
	}
	
	function getEventList() 
	{ 
		return array('QuickMenu', 'PostAddBlog', 'PostDeleteBlog', 'AdminPrePageFoot'); 
	}
	
	function getTableURLPart()
	{
		// select * from nucleus_plug_lmurlparts_urlpart;
		return sql_table('plug_lmurlparts_urlpart');
	}
	
	function getTableType()
	{
		// select * from nucleus_plug_lmurlparts_type;
		return sql_table('plug_lmurlparts_type');
	}

	function _createTableURLPart()
	{
		$query  = "CREATE TABLE IF NOT EXISTS ".$this->getTableURLPart();
		$query .= "( ";
		$query .= "urlpartid int(11) NOT NULL auto_increment, ";
		$query .= "urlpartname varchar(200) NOT NULL, ";
		$query .= "typeid int(11) NOT NULL, ";
		$query .= "refid int(11) NOT NULL, ";
		$query .= "blogid int(11) NOT NULL, ";
		$query .= "urlpart varchar(200) NOT NULL, ";
		$query .= "status char(1) NOT NULL, "; // urlpart: 'L' - Locked (Not automatically replaced when name changes), 'U' - Unlocked, 'D' - Delete candidate, 'F' - Fixed 
		$query .= "PRIMARY KEY (urlpartid) ";
		$query .= ") ";
		
		sql_query($query);
	}
	
	function _createTableType()
	{
		$query  = "CREATE TABLE IF NOT EXISTS ".$this->getTableType();
		$query .= "( ";
		$query .= "typeid int(11) NOT NULL auto_increment, ";
		$query .= "typename varchar(30) NOT NULL, ";
		$query .= "pluginname varchar(40) NOT NULL, ";
		$query .= "uniquecode char(1) NOT NULL, "; // 'T' - Type, 'B' - Blog, 'L' - Top Level, 'M' - Blog Multipart.
		$query .= "paramname varchar(30) NOT NULL, ";
		$query .= "typeorder int(11) NOT NULL, ";
		$query .= "UNIQUE KEY typename (typename, pluginname), ";
		$query .= "PRIMARY KEY (typeid) ";
		$query .= ") ";
		
		sql_query($query);		
	}

	function install()
	{
		$sourcedataversion = $this->getDataVersion();

		$this->upgradeDataPerform(1, $sourcedataversion);
		$this->setCurrentDataVersion($sourcedataversion);
		$this->upgradeDataCommit(1, $sourcedataversion);
		$this->setCommitDataVersion($sourcedataversion);					
	}
	
	function unInstall()
	{
		if ($this->getOption('del_uninstall') == 'yes')	
		{
			foreach ($this->getTableList() as $table) 
			{
				sql_query("DROP TABLE IF EXISTS ".$table);
			}
		}
	}

	function _initTableType()
	{
		if(!$this->_getKeyWordTopLevelTypeId())
		{
			$this->_insertType('KeyWord TopLevel', $this->getName(), 'L', '', 0);
		}
		
		if(!$this->_getKeyWordBlogLevelTypeId())
		{
			$this->_insertType('KeyWord BlogLevel', $this->getName(), 'B', '', 0);
		}

		if(!$this->_getKeyWordBlogLevelDefaultTypeId())
		{
			$this->_insertType('KeyWord BlogLevel Default', $this->getName(), 'T', '', 0);
		}
	}
	
	///////////////////////////////////////////
	// Events
	function event_QuickMenu(&$data) 
	{
		global $member;

		if (!$member->isAdmin() && !count($member->getAdminBlogs())) return;
			array_push($data['options'],
				array('title' => 'LMURLParts',
					'url' => $this->getAdminURL(),
					'tooltip' => 'Administer NP_LMURLParts'));
	}

	function event_PostAddBlog(&$data)
	{
		$oBlog = $data['blog'];

		$blogid = $oBlog->getId();

		$this->_initNewBlog($blogid);
	}
	
	function event_PostDeleteBlog(&$data)
	{
		$blogid = $data['blogid'];
		
		$this->removeURLPartForBlogId($blogid);
	}

	function event_AdminPrePageFoot(&$data)
	{
		// Workaround for missing event: AdminPluginNotification
		$data['notifications'] = array();
			
		$this->event_AdminPluginNotification($data);
			
		foreach($data['notifications'] as $aNotification)
		{
			echo '<h2>Notification from plugin: '.htmlspecialchars($aNotification['plugin'], ENT_QUOTES, _CHARSET).'</h2>';
			echo $aNotification['text'];
		}
	}
	
	function event_AdminPluginNotification(&$data)
	{
		global $member;
		
		$actions = array('overview', 'pluginlist', 'plugin_LMURLParts');
		$text = "";
		
		if(in_array($data['action'], $actions))
		{			
			$sourcedataversion = $this->getDataVersion();
			$commitdataversion = $this->getCommitDataVersion();
			$currentdataversion = $this->getCurrentDataVersion();
		
			if($currentdataversion > $sourcedataversion)
			{
				$text .= '<p>An old version of the '.htmlspecialchars($this->getName(), ENT_QUOTES, _CHARSET).' plugin files are installed. Downgrade of the plugin data is not supported. The correct version of the plugin files must be installed for the plugin to work properly.</p>';
			}
			
			if($currentdataversion < $sourcedataversion)
			{
				$text .= '<p>The version of the '.htmlspecialchars($this->getName(), ENT_QUOTES, _CHARSET).' plugin data is for an older version of the plugin than the version installed. ';
				$text .= 'The plugin data needs to be upgraded or the source files needs to be replaced with the source files for the old version before the plugin can be used. ';

				if($member->isAdmin())
				{
					$text .= 'Plugin data upgrade can be done on the '.htmlspecialchars($this->getName(), ENT_QUOTES, _CHARSET).' <a href="'.$this->getAdminURL().'">admin page</a>.';
				}
				
				$text .= '</p>';
			}
			
			if($commitdataversion < $currentdataversion && $member->isAdmin())
			{
				$text .= '<p>The version of the '.htmlspecialchars($this->getName(), ENT_QUOTES, _CHARSET).' plugin data is upgraded, but the upgrade needs to commited or rolled back to finish the upgrade process. ';
				$text .= 'Plugin data upgrade commit and rollback can be done on the '.htmlspecialchars($this->getName(), ENT_QUOTES, _CHARSET).' <a href="'.$this->getAdminURL().'">admin page</a>.</p>';
			}
		}
		
		if($text)
		{
			array_push(
				$data['notifications'],
				array(
					'plugin' => $this->getName(),
					'text' => $text
				)
			);
		}
	}

	///////////////////////////////////////////
	// Public functions on Type
	
	function addType($typename, $pluginname, $uniquecode, $paramname, $typeorder, $keyword)
	{	
		$aType = $this->_getTypeByName($typename, $pluginname);

		if($aType === false)
		{
			return false;
		}
		
		if(count($aType) == 0)
		{
			$typeid = $this->_insertType($typename, $pluginname, $uniquecode, $paramname, $typeorder);
				
			if($typeid === false)
			{
				return false;
			}
		}
		else
		{
			$typeid = $aType['0']['typeid'];
			
			$res = $this->_deleteURLPartByTypeKeepLocked($typeid);
			if($res === false) { return false; }
		}

		if($uniquecode == 'L')
		{
			$keywordtypeid = $this->_getKeyWordTopLevelTypeId();
			if($keywordtypeid === false) { return false; }
			
			$urlpartid = $this->addChangeURLPart($typename, $keywordtypeid, $typeid, 0);
			if($urlpartid === false) { return false; }
			
			if($keyword)
			{
				$urlpart = $this->_makeURLPart($urlpartid, $keyword, $keywordtypeid, $typeid, 0);
				if($urlpart === false) { return false; }
				
				$res = $this->_updateURLPartURLPart($urlpartid, $urlpart);
				if($res === false) { return false; }
			}
		}
		elseif($uniquecode == 'B')
		{
			$keywordtypeid = $this->_getKeyWordBlogLevelTypeId();
			if($keywordtypeid === false) { return false; }

			$defaulttypeid = $this->_getKeyWordBlogLevelDefaultTypeId();
			if($defaulttypeid === false) { return false; }

			$urlpartid = $this->addChangeURLPart($typename, $defaulttypeid, $typeid, 0);
			if($urlpartid === false) { return false; }
			
			if($keyword)
			{
				$urlpart = $this->_makeURLPart($urlpartid, $keyword, $defaulttypeid, $typeid, 0);
				if($urlpart === false) { return false; }
				
				$res = $this->_updateURLPartURLPart($urlpartid, $urlpart);
				if($res === false) { return false; }
			}

			$aBlogInfo = $this->_getBlogAll();
		
			foreach($aBlogInfo as $aBlog)
			{
				$blogid = $aBlog['blogid'];
				
				$urlpartid = $this->addChangeURLPart($typename, $keywordtypeid, $typeid, $blogid);
				if($urlpartid === false) { return false; }
				
				if($keyword)
				{
					$urlpart = $this->_makeURLPart($urlpartid, $keyword, $keywordtypeid, $typeid, $blogid);
					if($urlpart === false) { return false; }
					
					$res = $this->_updateURLPartURLPart($urlpartid, $urlpart);
					if($res === false) { return false; }
				}
			}
		}
		
		return $typeid;
	}

	function findTypeId($typename, $pluginname)
	{
		$aType = $this->_getTypeByName($typename, $pluginname);

		if($aType === false)
		{
			return false;
		}

		if(count($aType) > 0)
		{
			$typeid = $aType['0']['typeid'];
		}
		else
		{
			$typeid = 0;
		}

		return $typeid;
	}
 
 
	function removeType($typeid)
	{
		if($typeid > 0)
		{
			$aType = $this->_getTypeById($typeid);
			if($aType === false) { return false; }
			$aType = $aType['0'];

			$uniquecode = $aType['uniquecode'];
			
			if($uniquecode == 'L')
			{
				$keywordtypeid = $this->_getKeyWordTopLevelTypeId();
				if($keywordtypeid === false) { return false; }
			}
			elseif($uniquecode == 'B')
			{
				$keywordtypeid = $this->_getKeyWordBlogLevelTypeId();
				if($keywordtypeid === false) { return false; }

				$defaulttypeid = $this->_getKeyWordBlogLevelDefaultTypeId();
				if($defaulttypeid === false) { return false; }

				$res = $this->_deleteURLPartByTypeIdRefId($defaulttypeid, $typeid);
				if($res === false) { return false; }
			}
			else
			{
				$keywordtypeid = false;
			}

			if($keywordtypeid)
			{
				$res = $this->_deleteURLPartByTypeIdRefId($keywordtypeid, $typeid);
				if($res === false) { return false; }
			}
			
			$res = $this->_deleteURLPartByType($typeid);
			if($res === false) { return false; }
			
			$res = $this->_deleteType($typeid);
			if($res === false) { return false; }
		}
		return true;
	}

	function findKeyWordForTypeId($typeid, $blogid)
	{
		$aType = $this->_getTypeById($typeid);
		if($aType === false) { return false; }
		$aType = $aType['0'];

		$uniquecode = $aType['uniquecode'];
		
		if($uniquecode == 'L')
		{
			$keywordtypeid = $this->_getKeyWordTopLevelTypeId();
			if($keywordtypeid === false) { return false; }
			
			$blogid = 0;
		} 
		elseif($uniquecode == 'B')
		{
			$keywordtypeid = $this->_getKeyWordBlogLevelTypeId();
			if($keywordtypeid === false) { return false; }
		}
		else
		{
			$keywordtypeid = false;
		}

		if($keywordtypeid)
		{
			$aURLPart = $this->_getURLPartByTypeIdRefIdBlogId($keywordtypeid, $typeid, $blogid);
			if($aURLPart === false) { return false; }
			if(!$aURLPart)  { return false; }
			$aURLPart = $aURLPart['0'];
		
			$keyword = $aURLPart['urlpart'];
		}
		else
		{
			$keyword = '';
		}

		return $keyword;
	}

	function findTypeByParamName($paramname)
	{
		return $this->_getTypeByParamName($paramname);
	}

	function findTypeByTypeName($typename)
	{
		return $this->_getTypeByName($typename);
	}
	
	///////////////////////////////////////////
	// Public functions on URLPart
	function addChangeURLPart($urlpartname, $typeid, $refid, $blogid, $urlpart = '')
	{
		$aURLPart = $this->_getURLPart(0, $typeid, $refid, $urlpartname, $blogid);
		
		if($aURLPart === false)
		{
			return false;
		}

		if($urlpart)
		{
			$fixedurlpart = true;
		}
		else
		{
			$urlpart = $urlpartname;
			$fixedurlpart = false;
		}
		
		if(count($aURLPart) > 0)
		{
			// Update
			$aURLPart = $aURLPart['0'];
			
			$oldurlpartname = $aURLPart['urlpartname'];
			$urlpartid = $aURLPart['urlpartid'];
			$oldurlpart = $aURLPart['urlpart'];
			$status = $aURLPart['status'];
			
			if(($status == 'U' || $status == 'D' || $fixedurlpart) && (($fixedurlpart == false && $urlpartname <> $oldurlpartname) || ($fixedurlpart == true && $urlpart <> $oldurlpart))) // Unlocked and changed
			{
				$res = $this->_makeURLPart($urlpartid, $urlpart, $typeid, $refid, $blogid);
				if($res === false)
				{
					return false;
				}
				$urlpart = $res;
			}
			else
			{
				$urlpart = $oldurlpart;
			}
			
			if($status == 'D') //Change delete candidate status to unlocked on update.
			{
				$status = 'U';
			}
			
			if($fixedurlpart)
			{
				$status = 'F';
			}
			
			$res = $this->_updateURLPart($urlpartid, $urlpartname, $typeid, $refid, $blogid, $urlpart, $status);
			if($res === false)
			{
				return false;
			}
		}
		else
		{
			// Insert
			$res = $this->_makeURLPart(0, $urlpart, $typeid, $refid, $blogid);
			if($res === false)
			{
				return false;
			}
			$urlpart = $res;

			if($fixedurlpart)
			{
				$status = 'F';
			}
			else
			{
				$status = 'U';
			}

			$res = $this->_insertURLPart($urlpartname, $typeid, $refid, $blogid, $urlpart, $status);
			
			if($res === false)
			{
				return false;
			}
			
			$urlpartid = $res;
		}
		
		return $urlpartid;
	}

	function changeURLPart($urlpartid, $urlpart, $status = '')
	{
		$res = $this->_updateURLPartURLPart($urlpartid, $urlpart);
		if($res === false) { return false; }

		if($status)
		{
			$res = $this->_updateURLPartStatusByURLPartId($urlpartid, $status);
			if($res === false) { return false; }
		}
		return true;
	}
	
	function changeURLPartStatus($urlpartid, $status)
	{
		return $this->_updateURLPartStatusByURLPartId($urlpartid, $status);
	}

	function removeURLPartForBlogId($blogid)
	{
		return $this->_deleteURLPartByBlogId($blogid);
	}
	
	function removeURLPartForTypeIdAndBlogId($typeid, $blogid)
	{
		return $this->_deleteURLPartByTypeIdAndBlogIdKeepLocked($typeid, $blogid);
	}

	function removeURLPart($urlpartname, $typeid, $refid, $blogid)
	{
		$aURLPart = $this->_getURLPart(0, $typeid, $refid, $urlpartname, $blogid);

		if($aURLPart === false) { return false; }

		if(count($aURLPart) > 0)
		{
			$aURLPart = $aURLPart['0'];
			$urlpartid = $aURLPart['urlpartid'];
			
			$res = $this->_deleteURLPartByURLPartId($urlpartid);
			
			if(!$res) { return false; }
		}

		return true;
	}
	
	function urlPartMaintStart($typeid, $blogid = 0)
	{
		return $this->_updateURLPartStatusByTypeIdBlogId($typeid, $blogid, 'D');
	}
	
	function urlPartMaintDone($typeid, $blogid = 0)
	{
		return $this->_deleteURLPartByTypeIdBlogIdDelCandidate($typeid, $blogid);
	}

	function findURLPartByTypeIdRefIdBlogId($typeid, $refid, $blogid)
	{
		$aType = $this->_getTypeById($typeid);
		if($aType === false) { return false; }
		$aType = $aType['0'];

		$uniquecode = $aType['uniquecode'];
		
		if($uniquecode == 'L')
		{
			$blogid = 0;
		} 
	
		$aURLPart = $this->_getURLPartByTypeIdRefIdBlogId($typeid, $refid, $blogid);
		if($aURLPart === false) { return false; }
		if(!$aURLPart) { return ''; }
		
		$aURLPart = $aURLPart['0'];
		
		$urlpart = $aURLPart['urlpart'];
		
		return $urlpart;
	}

	function findURLPartByTypeIdURLPartNameBlogId($typeid, $urlpartname, $blogid)
	{
		$aType = $this->_getTypeById($typeid);
		if($aType === false) { return false; }
		$aType = $aType['0'];

		$uniquecode = $aType['uniquecode'];
		
		if($uniquecode == 'L')
		{
			$blogid = 0;
		} 
	
		$aURLPart = $this->_getURLPartByTypeIdURLPartNameBlogId($typeid, $urlpartname, $blogid);
		if($aURLPart === false) { return false; }
		if(!$aURLPart) { return ''; }
		
		$aURLPart = $aURLPart['0'];
		
		$urlpart = $aURLPart['urlpart'];
		
		return $urlpart;
	}

	function findURLPartForParseURL($urlpart, $blogid)
	{
		if(!$blogid)
		{
			$blogid = 0;
		}
		
		return $this->_getURLPartForParseURL($urlpart, $blogid);
	}
	
	function findURLPartByTypeIdBlogId($typeid, $blogid)
	{
		return $this->_getURLPartByTypeIdBlogId($typeid, $blogid);
	}
		
	/////////////////////////////////////////////////////////
	// Internal functions

	function _makeURLPart($urlpartid, $urlpartname, $typeid, $refid, $blogid)
	{
		$aType = $this->_getTypeById($typeid);
		
		if($aType === false)
		{
			return false;
		}
		$aType = $aType['0'];
		$uniquecode = $aType['uniquecode'];

		$urlname = $this->_makeURLFriendly($urlpartname, $uniquecode);

		if(!$urlname)
		{
			$urlname = $aType['typename'];
			
			if($refid > 0)
			{
				$urlname .= $refid;
			}
			$urlname = $this->_makeURLFriendly($urlname, $uniquecode);
		}

		$res = 1;
		$urlnumber = 1;
		
		while($res)
		{
			if ($urlnumber > 1) 
			{
				$urlpart = $urlname.'-'.$urlnumber;
			}
			else
			{
				$urlpart = $urlname;
			}
						
			$res = $this->_existURLPart($typeid, $blogid, $urlpart, $uniquecode, $urlpartid);

			if($res === false)
			{
				return false;
			}
			
			if($res)
			{
				$urlnumber++;
			}
		}

		return $urlpart;
	}

	function _checkURLPartInUse($typeid, $blogid, $urlpart)
	{
		$aType = $this->_getTypeById($typeid);
		if($aType === false) return false;
		$aType = $aType['0'];
		
		$uniquecode = $aType['uniquecode'];
		
		return $this->_existURLPart($typeid, $blogid, $urlpart, $uniquecode, 0);
	}
	
	function _makeURLFriendly($text, $uniquecode)
	{
		$text = trim($text);

		$text = str_replace(array(' ', '"', "'"), array('-', '-', '-'), $text);

		$enc = trim($this->getOption('encoding'));

		$text = $this->_convertTo7bit($text, $enc);

		$text = strtolower(trim($text));

		// remove untranslated letters:
		// only letters, numbers and underscores are allowed
		if($uniquecode == 'M')
		{
			$pretext = '';
			while($text <> $pretext)
			{
				$pretext = $text;
				$text = str_replace('//', '/', $text);
			}
			
			$text = preg_replace("#[^a-z0-9_/-]#", "", $text);

			if(substr($text, 0, 1) != '/')
			{
				$text = '/'.$text;
			}
		}
		else
		{
			$text = preg_replace("/[^a-z0-9_-]/", "", $text);
		}
		
		if((string) intval($text) == (string) $text)
		{
			$text = '';
		}
		
		return $text;
	}
	
	function _convertTo7bit($text, $from_enc = "") 
	{
		if($from_enc == "" || strtolower($from_enc) == "auto")
		{
			$from_enc = _CHARSET;
		}

		if($from_enc == 'ASCII')
		{
			$from_enc ='UTF-8';
		}

		$newtext = htmlentities($text, ENT_QUOTES, $from_enc);
		
		if($newtext)
		{
			$text = $newtext;
		}

		if(strpos($text, '&') !== false)
		{
		   $text = preg_replace(
				array('/&amp;/', '/&[lg]t;/', '/&szlig;/','/&(..)lig;/','/&([aou])uml;/','/&([AOU])uml;/',
					'/&Aring;/','/&aring;/','/&Oslash;/','/&oslash;/','/&(.)[^;]*;/'),
				array('', '', 'ss', "$1", "$1".'e', "$1".'E', 'AA', 'aa', 'OE', 'oe', "$1"),
				$text);
		}

		return $text;
	}
	
	function _initNewBlog($blogid)
	{
		$keywordtypeid = $this->_getKeyWordBlogLevelTypeId();
		if($keywordtypeid === false) { return false; }

		$defaulttypeid = $this->_getKeyWordBlogLevelDefaultTypeId();
		if($defaulttypeid === false) { return false; }

		$aURLPartInfo = $this->_getURLPartByType($defaulttypeid);
		if($aURLPartInfo === false) { return false; }
		
		foreach($aURLPartInfo as $aURLPart)
		{
			$refid   = $aURLPart['refid'];
			$urlpartname = $aURLPart['urlpartname'];
			$urlpart = $aURLPart['urlpart'];

			$urlpartid = $this->addChangeURLPart($urlpartname, $keywordtypeid, $refid, $blogid);
			if($urlpartid === false) { return false; }
			
			$urlpart = $this->_makeURLPart($urlpartid, $urlpart, $keywordtypeid, $refid, $blogid);
			if($urlpart === false) { return false; }
				
			$res = $this->_updateURLPartURLPart($urlpartid, $urlpart);
			if($res === false) { return false; }
		}
		
		return true;
	}
	
	function _getKeyWordBlogLevelTypeId()
	{
		return $this->findTypeId("KeyWord BlogLevel", $this->getName());
	}

	function _getKeyWordBlogLevelDefaultTypeId()
	{
		return $this->findTypeId("KeyWord BlogLevel Default", $this->getName());
	}

	function _getKeyWordTopLevelTypeId()
	{
		return $this->findTypeId('KeyWord TopLevel', $this->getName());
	}
		
	/////////////////////////////////////////////////////////
	// Data access functions on Type

	function _insertType($typename, $pluginname, $uniquecode, $paramname, $typeorder)
	{
		$query = "INSERT ".$this->getTableType()." (typename, pluginname, uniquecode, paramname, typeorder) "
				."VALUES ('".sql_real_escape_string($typename)."', "
					."'".sql_real_escape_string($pluginname)."', "
					."'".sql_real_escape_string($uniquecode)."', "
					."'".sql_real_escape_string($paramname)."', "
					.$typeorder.")";
					
		$res = sql_query($query);
		
		if(!$res)
		{
			return false;

		}
		
		$typeid = sql_insert_id();
		
		return $typeid;
	}
	
	function _deleteType($typeid)
	{
		$aURLPart = $this->_getURLPartByType($typeid);
		
		if($aURLPart === false)
		{
			return false;
		}
		
		if(count($aURLPart) > 0)
		{
			return false;
		}
		
		$query = "DELETE FROM ".$this->getTableType()." "
				."WHERE typeid = ".$typeid." ";
					
		$res = sql_query($query);
		
		if(!$res)
		{
			return false;

		}
		
		return true;
	}

	function _getTypeByName($typename, $pluginname = '')
	{
		return $this->_getType(0, $typename, $pluginname, '', '');
	}
	
	function _getTypeById($typeid)
	{
		return $this->_getType($typeid, '', '', '', '');
	}

	function _getTypeByUniqueCode($uniquecode)
	{
		return $this->_getType(0, '', '', $uniquecode, '');
	}
	
	function _getTypeByParamName($paramname)
	{
		return $this->_getType(0, '', '', '', $paramname);
	}

	function _getType($typeid, $typename, $pluginname, $uniquecode, $paramname)
	{
		$ret = array();
		
		$query = "SELECT typeid, typename, pluginname, uniquecode, paramname, typeorder "
			."FROM ".$this->getTableType()." ";

		if($typeid)
		{
			$query .= "WHERE typeid = ".$typeid." ";
		}
		elseif($typename)
		{
			$query .= "WHERE typename = '".sql_real_escape_string($typename)."' ";
			
			if($pluginname)
			{
				$query .="AND pluginname = '".sql_real_escape_string($pluginname)."' ";
			}
		}
		elseif($uniquecode)
		{
			$query .= "WHERE uniquecode = '".sql_real_escape_string($uniquecode)."' ";
		}
		elseif($paramname)
		{
			$query .= "WHERE paramname = '".sql_real_escape_string($paramname)."' AND uniquecode <> 'M' ";
		}

		$res = sql_query($query);
		
		if($res)
		{
			while ($o = sql_fetch_object($res)) 
			{
				array_push($ret, array(
					'typeid'    	=> $o->typeid,
					'typename'      => $o->typename,
					'pluginname'	=> $o->pluginname,
					'uniquecode'	=> $o->uniquecode,
					'paramname'		=> $o->paramname,
					'typeorder'		=> $o->typeorder
					));
			}
		}
		else
		{
			return false;
		}

		return $ret;
	}

	/////////////////////////////////////////////////////////
	// Data access functions on URLPart

	function _getURLPartByTypeIdURLPartNameBlogId($typeid, $urlpartname, $blogid)
	{
		return $this->_getURLPart(0, $typeid, 0, $urlpartname, $blogid);
	}
	
	function _getURLPartByURLPartIdBlogId($urlpartid, $blogid)
	{
		return $this->_getURLPart($urlpartid, 0, 0, '', $blogid);
	}

	function _getURLPartByType($typeid)
	{
		return $this->_getURLPart(0, $typeid, 0, '', 0);
	}

	function _getURLPartByTypeIdBlogId($typeid, $blogid)
	{
		return $this->_getURLPart(0, $typeid, 0, '', $blogid);
	}

	function _getURLPartByTypeIdRefIdBlogId($typeid, $refid, $blogid)
	{
		return $this->_getURLPart(0, $typeid, $refid, '', $blogid);
	}
	
	function _getURLPart($urlpartid, $typeid, $refid, $urlpartname, $blogid)
	{
		$ret = array();
		
		$query = "SELECT urlpartid, urlpartname, typeid, refid, blogid, urlpart, status "
				."FROM ".$this->getTableURLPart()." ";

		if($urlpartid)
		{
			$query .= "WHERE urlpartid = ".$urlpartid." ";
		}
		elseif($refid)
		{
			$query .= "WHERE refid = ".$refid." AND typeid = ".$typeid." ";
		}
		elseif($urlpartname)
		{
			$query .= "WHERE urlpartname = '".sql_real_escape_string($urlpartname)."' AND typeid = ".$typeid." ";
		}
		elseif($typeid)
		{
			$query .= "WHERE typeid = ".$typeid." ";
		}
		
		if($blogid)
		{
			$query .= "AND blogid = ".$blogid." ";
		}

		$query .= "ORDER BY urlpartname ";
		
		$res = sql_query($query);
		
		if($res)
		{
			while ($o = sql_fetch_object($res)) 
			{
				array_push($ret, array(
					'urlpartid'    => $o->urlpartid,
					'urlpartname'       => $o->urlpartname,
					'typeid'		=> $o->typeid,
					'refid'       => $o->refid,
					'blogid'       => $o->blogid,
					'urlpart'         => $o->urlpart,
					'status'      => $o->status
					));
			}
		}
		else
		{
			return false;
		}
	
		return $ret;
	}

	function _getURLPartForParseURL($urlpart, $blogid)
	{
		$ret = array();

		$query = "SELECT u.urlpartid, u.urlpartname, u.typeid, u.refid, u.blogid, u.urlpart, u.status, t.typename, t.uniquecode, t.paramname "
				."FROM ".$this->getTableURLPart()." u, ".$this->getTableType()." t "
				."WHERE u.typeid = t.typeid "
				."AND u.blogid = ".$blogid." "
				."AND u.urlpart = '".sql_real_escape_string($urlpart)."' "
				."AND t.uniquecode IN ('L', 'B', 'M') ";

		$res = sql_query($query);
		
		if($res)
		{
			while ($o = sql_fetch_object($res)) 
			{
				array_push($ret, array(
					'urlpartid'		=> $o->urlpartid,
					'urlpartname'	=> $o->urlpartname,
					'typeid'		=> $o->typeid,
					'refid'			=> $o->refid,
					'blogid'		=> $o->blogid,
					'urlpart'		=> $o->urlpart,
					'status'		=> $o->status,
					'typename'		=> $o->typename,
					'uniquecode'	=> $o->uniquecode,
					'paramname'		=> $o->paramname
					));
			}
		}
		else
		{
			return false;
		}
	
		return $ret;
	}
	
	function _insertURLPart($urlpartname, $typeid, $refid, $blogid, $urlpart, $status)
	{
		$query = "INSERT ".$this->getTableURLPart()." (urlpartname, typeid, refid, blogid, urlpart, status) "
				."VALUES ('".sql_real_escape_string($urlpartname)."', "
					.$typeid.", "
					.$refid.", "
					.$blogid.", "
					."'".sql_real_escape_string($urlpart)."', "
					."'".sql_real_escape_string($status)."')";
					
		$res = sql_query($query);
		
		if(!$res)
		{
			return false;

		}
		
		$urlpartid = sql_insert_id();
		
		return $urlpartid;
	}

	function _updateURLPart($urlpartid, $urlpartname, $typeid, $refid, $blogid, $urlpart, $status)
	{
		$query = "UPDATE ".$this->getTableURLPart()." SET "
				."urlpartname = '".sql_real_escape_string($urlpartname)."', "
				."typeid = ".$typeid.", "
				."refid = ".$refid.", "
				."blogid = ".$blogid.", "
				."urlpart = '".sql_real_escape_string($urlpart)."', "
				."status = '".sql_real_escape_string($status)."' "
				."WHERE urlpartid = ".$urlpartid." ";
					
		$res = sql_query($query);
		
		if(!$res)
		{
			return false;

		}
				
		return true;
	}
	
	function _updateURLPartStatusByTypeIdBlogId($typeid, $blogid, $status)
	{
		return $this->_updateURLPartStatus(0, $status, $typeid, $blogid);
	}

	function _updateURLPartStatusByURLPartId($urlpartid, $status)
	{
		return $this->_updateURLPartStatus($urlpartid, $status, 0, 0);
	}
	
	function _updateURLPartStatus($urlpartid, $status, $typeid, $blogid)
	{
		$query = "UPDATE ".$this->getTableURLPart()." SET "
				."status = '".sql_real_escape_string($status)."' "
				."WHERE status <> 'F' ";
				
		if($urlpartid)
		{
			$query .= "AND urlpartid = ".$urlpartid." ";
		}
		elseif($typeid)
		{
			$query .= "AND typeid = ".$typeid." ";
					
			if($blogid)
			{
				$query .= "AND blogid = ".$blogid." ";
			}
		}
		
		$res = sql_query($query);
		
		if(!$res)
		{
			return false;

		}
				
		return true;
	}
	
	function _updateURLPartURLPart($urlpartid, $urlpart)
	{
		$query = "UPDATE ".$this->getTableURLPart()." SET "
				."urlpart = '".sql_real_escape_string($urlpart)."' "
				."WHERE urlpartid = ".$urlpartid." ";
					
		$res = sql_query($query);
		
		if(!$res)
		{
			return false;

		}
				
		return true;
	}

	function _deleteURLPartByTypeIdBlogIdDelCandidate($typeid, $blogid)
	{
		return $this->_deleteURLPart(0, $typeid, $blogid, false, true, 0);
	}

	function _deleteURLPartByBlogId($blogid)
	{
		return $this->_deleteURLPart(0, 0, $blogid, false, false, 0);
	}
	
	function _deleteURLPartByURLPartId($urlpartid)
	{
		return $this->_deleteURLPart($urlpartid, 0, 0, false, false, 0);
	}
	
	function _deleteURLPartByType($typeid)
	{
		return $this->_deleteURLPart(0, $typeid, 0, false, false, 0);
	}

	function _deleteURLPartByTypeIdAndBlogIdKeepLocked($typeid, $blogid)
	{
		return $this->_deleteURLPart(0, $typeid, $blogid, false, false, 0);
	}
	
	function _deleteURLPartByTypeKeepLocked($typeid)
	{
		return $this->_deleteURLPart(0, $typeid, 0, true, false, 0);
	}

	function _deleteURLPartByTypeIdRefId($typeid, $refid)
	{
		return $this->_deleteURLPart(0, $typeid, 0, false, false, $refid);
	
	}
	
	function _deleteURLPart($urlpartid, $typeid, $blogid, $keepLocked, $deleteCandidate, $refid)
	{
		$query = "DELETE FROM ".$this->getTableURLPart()." ";
		
		if($urlpartid)
		{
			$query .= "WHERE urlpartid = ".$urlpartid." ";
		}
		elseif($typeid)
		{
			$query .= "WHERE typeid = ".$typeid." ";
			
			if($blogid)
			{
				$query .= "AND blogid = ".$blogid." ";
			}
			
			if($refid)
			{
				$query .= "AND refid = ".$refid." ";
			}
		}
		elseif($blogid)
		{
			$query .= "WHERE blogid = ".$blogid." ";
		}
		else
		{
			return false;
		}
	
		if($keepLocked)
		{
			$query .= "AND status <> 'L' ";
		}

		if($deleteCandidate)
		{
			$query .= "AND status = 'D' ";
		}

		$res = sql_query($query);
		
		if(!$res)
		{
			return false;
		}
		
		return true;
	}

	function _existURLPart($typeid, $blogid, $urlpart, $uniquecode, $noturlpartid)
	{
		$urlpartid = 0;
		
		$query = "SELECT urlpartid FROM ".$this->getTableURLPart()." "
				."WHERE urlpart = '".$urlpart."' ";
	
		switch($uniquecode)
		{
			case 'T':
				$query .= "AND typeid = ".$typeid." ";
				break;
				
			case 'M':
			case 'B':
				$query .= "AND blogid = ".$blogid." ";
				break;
				
			case 'L':
				$query .= "AND typeid IN (SELECT typeid FROM ".$this->getTableType()." WHERE uniquecode = 'L') ";
				break;
				
			default:
				return false;
		}
		
		if($noturlpartid)
		{
			$query .= "AND urlpartid <> ".$noturlpartid." ";
		}

		$res = sql_query($query);
		
		if($res)
		{
			while ($o = sql_fetch_object($res)) 
			{
				$urlpartid = $o->urlpartid;
			}
		}
		else
		{
			return false;
		}
		return $urlpartid;
	}

	////////////////////////////////////////////////////////////////////////
	// Internal functions: Data access Blog
	
	function _getBlogAll()
	{
		return $this->_getBlogInfo();
	}

	function _getBlogInfo()
	{
		$ret = array();
		
		$query = "SELECT bnumber AS blogid, bname AS blogname, bdefskin AS skinid FROM ".sql_table('blog');
		$res = sql_query($query);
		
		if($res)
		{
			while ($o = sql_fetch_object($res)) 
			{
				array_push($ret, array(
					'blogid'	=> $o->blogid,
					'blogname'	=> $o->blogname,
					'skinid'	=> $o->skinid
					));
			}
		}
		else
		{
			return false;
		}
		return $ret;
	}

	////////////////////////////////////////////////////////////////////////
	// Plugin Upgrade handling functions
	function getCurrentDataVersion()
	{
		$currentdataversion = $this->getOption('currentdataversion');
		
		if(!$currentdataversion)
		{
			$currentdataversion = 1;
		}
		
		return $currentdataversion;
	}

	function setCurrentDataVersion($currentdataversion)
	{
		$res = $this->setOption('currentdataversion', $currentdataversion);
		$this->clearOptionValueCache(); // Workaround for bug in Nucleus Core
		
		return $res;
	}

	function getCommitDataVersion()
	{
		$commitdataversion = $this->getOption('commitdataversion');
		
		if(!$commitdataversion)
		{
			$commitdataversion = 1;
		}

		return $commitdataversion;
	}

	function setCommitDataVersion($commitdataversion)
	{	
		$res = $this->setOption('commitdataversion', $commitdataversion);
		$this->clearOptionValueCache(); // Workaround for bug in Nucleus Core
		
		return $res;
	}

	function getDataVersion()
	{
		return 3;
	}
	
	function upgradeDataTest($fromdataversion, $todataversion)
	{
		// returns true if rollback will be possible after upgrade
		$res = true;
				
		return $res;
	}
	
	function upgradeDataPerform($fromdataversion, $todataversion)
	{
		// Returns true if upgrade was successfull
		
		for($ver = $fromdataversion; $ver <= $todataversion; $ver++)
		{
			switch($ver)
			{
				case 1:
					$this->createOption ('encoding','Choose encoding automatic (AUTO) or manual (ISO-8859-1, UTF-8 or ASCII):', 'select', 'AUTO', 'AUTO|AUTO|ISO-8859-1|ISO-8859-1|UTF-8|UTF-8|ASCII|ASCII');
					$this->createOption('del_uninstall', 'Delete NP_LMURLParts data tables on uninstall?', 'yesno','no');

					$this->_createTableURLPart();
					$this->_createTableType();
					$this->_initTableType();
					$res = true;
					break;
				case 2:
					$this->createOption('currentdataversion', 'currentdataversion', 'text','1', 'access=hidden');
					$this->createOption('commitdataversion', 'commitdataversion', 'text','1', 'access=hidden');
					$res = true;
					break;
				case 3:
					$res = true;
					break;
				default:
					$res = false;
					break;
			}
			
			if(!$res)
			{
				return false;
			}
		}
		
		return true;
	}
	
	function upgradeDataRollback($fromdataversion, $todataversion)
	{
		// Returns true if rollback was successfull
		for($ver = $fromdataversion; $ver >= $todataversion; $ver--)
		{
			switch($ver)
			{
				case 2:
					$this->deleteOption('currentdataversion');
					$this->deleteOption('commitdataversion');
					$res = true;
					break;
				case 3:
					$this->_rollbackDataVersion3();
					$res = true;
					break;
				
				default:
					$res = false;
					break;
			}
			
			if(!$res)
			{
				return false;
			}
		}

		return true;
	}

	function upgradeDataCommit($fromdataversion, $todataversion)
	{
		// Returns true if commit was successfull
		for($ver = $fromdataversion; $ver <= $todataversion; $ver++)
		{
			switch($ver)
			{
				case 2:
				case 3:
					$res = true;
					break;
				default:
					$res = false;
					break;
			}
			
			if(!$res)
			{
				return false;
			}
		}
		return true;
	}
	
	function _checkColumnIfExists($table, $column)
	{
		// Retuns: $column: Found, '' (empty string): Not found, false: error
		$found = '';
		
		$res = sql_query("SELECT * FROM ".$table." WHERE 1 = 2");

		if($res)
		{
			$numcolumns = sql_num_fields($res);

			for($offset = 0; $offset < $numcolumns && !$found; $offset++)
			{
				if(sql_field_name($res, $offset) == $column)
				{
					$found = $column;
				}
			}
		}
		
		return $found;
	}
	
	function _addColumnIfNotExists($table, $column, $columnattributes)
	{
		$found = $this->_checkColumnIfExists($table, $column);
		
		if($found === false) 
		{
			return false;
		}
		
		if(!$found)
		{
			$res = sql_query("ALTER TABLE ".$table." ADD ".$column." ".$columnattributes);

			if(!$res)
			{
				return false;
			}
		}

		return true;
	}

	function _dropColumnIfExists($table, $column)
	{
		$found = $this->_checkColumnIfExists($table, $column);
		
		if($found === false) 
		{
			return false;
		}
		
		if($found)
		{
			$res = sql_query("ALTER TABLE ".$table." DROP COLUMN ".$column);

			if(!$res)
			{
				return false;
			}
		}

		return true;
	}
	
	function _rollbackDataVersion3()
	{
		$aTypeInfo = $this->_getTypeByUniqueCode('M');
		
		foreach($aTypeInfo as $aType)
		{
			$typeid = $aType['typeid'];
			
			$res = $this->_deleteURLPartByType($typeid);
			if($res === false) { return false; }
			
			$res = $this->_deleteType($typeid);
			if($res === false) { return false; }
		}
	}
}
?>