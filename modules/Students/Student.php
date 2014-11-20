<?php

include('ProgramFunctions/FileUpload.fnc.php');
	
if(!$_REQUEST['include'])
{
	$_REQUEST['include'] = 'General_Info';
	$_REQUEST['category_id'] = '1';
}
elseif(!$_REQUEST['category_id'])
{
	if($_REQUEST['include']== 'General_Info')
		$_REQUEST['category_id'] = '1';
	elseif($_REQUEST['include']== 'Address')
		$_REQUEST['category_id'] = '3';
	elseif($_REQUEST['include']== 'Medical')
		$_REQUEST['category_id'] = '2';
	elseif($_REQUEST['include']== 'Comments')
		$_REQUEST['category_id'] = '4';
	elseif($_REQUEST['include']!= 'Other_Info')
	{
		$include = DBGet(DBQuery("SELECT ID FROM STUDENT_FIELD_CATEGORIES WHERE INCLUDE='".$_REQUEST['include']."'"));
		$_REQUEST['category_id'] = $include[1]['ID'];
	}
}

if(User('PROFILE')!='admin')
{
	if(User('PROFILE')!='student')
		if(User('PROFILE_ID'))
			$can_edit_RET = DBGet(DBQuery("SELECT MODNAME FROM PROFILE_EXCEPTIONS WHERE PROFILE_ID='".User('PROFILE_ID')."' AND MODNAME='Students/Student.php&category_id=".$_REQUEST['category_id']."' AND CAN_EDIT='Y'"));
		else
			$can_edit_RET = DBGet(DBQuery("SELECT MODNAME FROM STAFF_EXCEPTIONS WHERE USER_ID='".User('STAFF_ID')."' AND MODNAME='Students/Student.php&category_id=".$_REQUEST['category_id']."' AND CAN_EDIT='Y'"),array(),array('MODNAME'));
	else
		$can_edit_RET = DBGet(DBQuery("SELECT MODNAME FROM PROFILE_EXCEPTIONS WHERE PROFILE_ID='0' AND MODNAME='Students/Student.php&category_id=".$_REQUEST['category_id']."' AND CAN_EDIT='Y'"));
	if($can_edit_RET)
		$_ROSARIO['allow_edit'] = true;
}

if($_REQUEST['modfunc']=='update' && AllowEdit())
{
	if(count($_REQUEST['month_students']))
	{
		foreach($_REQUEST['month_students'] as $column=>$value)
		{
			$_REQUEST['students'][$column] = $_REQUEST['day_students'][$column].'-'.$_REQUEST['month_students'][$column].'-'.$_REQUEST['year_students'][$column];
			//modif Francois: bugfix SQL bug when incomplete or non-existent date
			//if($_REQUEST['students'][$column]=='--')
			if(mb_strlen($_REQUEST['students'][$column]) < 11)
				$_REQUEST['students'][$column] = '';
			else
			{
				while(!VerifyDate($_REQUEST['students'][$column]))
				{
					$_REQUEST['day_students'][$column]--;
					$_REQUEST['students'][$column] = $_REQUEST['day_students'][$column].'-'.$_REQUEST['month_students'][$column].'-'.$_REQUEST['year_students'][$column];
				}
			}
		}
	}
	unset($_REQUEST['day_students']); unset($_REQUEST['month_students']); unset($_REQUEST['year_students']);

	if((count($_REQUEST['students']) || count($_REQUEST['values'])) && AllowEdit())
	{
		//modif Francois: fix SQL bug FIRST_NAME, LAST_NAME is null
		if ((isset($_REQUEST['students']['FIRST_NAME']) && empty($_REQUEST['students']['FIRST_NAME'])) || (isset($_REQUEST['students']['LAST_NAME']) && empty($_REQUEST['students']['LAST_NAME'])))
		{
			$error[] = _('Please fill in the required fields');
		}

		if(UserStudentID() && !isset($error))
		{

			//hook
			do_action('Students/Student.php|update_student_checks');

			if(count($_REQUEST['students']) && !isset($error))
			{
				$sql = "UPDATE STUDENTS SET ";
				$fields_RET = DBGet(DBQuery("SELECT ID,TYPE FROM CUSTOM_FIELDS ORDER BY SORT_ORDER"), array(), array('ID'));
				$go = false;
				foreach($_REQUEST['students'] as $column=>$value)
				{
					if(1)//!empty($value) || $value=='0')
					{
						//modif Francois: check numeric fields
						if ($fields_RET[str_replace('CUSTOM_','',$column)][1]['TYPE'] == 'numeric' && $value!='' && !is_numeric($value))
						{
							$error[] = _('Please enter valid Numeric data.');
							continue;
						}
						
						if($column=='USERNAME' && $value)
							if(DBGet(DBQuery("SELECT STUDENT_ID FROM STUDENTS WHERE USERNAME='".$value."' AND STUDENT_ID<>'".UserStudentID()."'")))
								$value = '';
						if(!is_array($value))
						{
							//modif Francois: add password encryption
							if ($column!=='PASSWORD')
							{
								$sql .= $column."='".str_replace('&#39;',"''",$value)."',";
								$go = true;
							}
							if ($column=='PASSWORD' && !empty($value) && $value!==str_repeat('*',8))
							{
								$value = str_replace("''","'",$value);
								$sql .= $column."='".encrypt_password($value)."',";
								$go = true;
							}
						}
						else
						{
							//modif Francois: fix bug none selected not saved
							$sql .= $column."='";
							$sql_multiple_input = '';
							foreach($value as $val)
							{
								if($val)
									$sql_multiple_input .= str_replace('&quot;','"',$val).'||';
							}
							if (!empty($sql_multiple_input))
								$sql .= "||".$sql_multiple_input;
							$sql .= "',";
							$go = true;
						}
					}
				}
				$sql = mb_substr($sql,0,-1) . " WHERE STUDENT_ID='".UserStudentID()."'";

				if($go)
				{
					DBQuery($sql);
					
					//hook
					do_action('Students/Student.php|update_student');
				}
			}

		}
		elseif (!isset($error)) //new student
		{
			if($_REQUEST['assign_student_id'])
			{
				if(is_numeric($_REQUEST['assign_student_id']))
				{
					$student_id = $_REQUEST['assign_student_id'];
					if(count(DBGet(DBQuery("SELECT STUDENT_ID FROM STUDENTS WHERE STUDENT_ID='".$student_id."'"))))
					{
						$error[] = sprintf(_('That %s ID is already taken. Please select a different one.'),Config('NAME'));
					}
				}
				else
					$error[] = _('Please enter valid Numeric data.');
			}

			//hook
			do_action('Students/Student.php|create_student_checks');

			if (!isset($error))
			{
				if (!isset($student_id))
					do
					{
						$student_id = DBGet(DBQuery('SELECT '.db_seq_nextval('STUDENTS_SEQ').' AS STUDENT_ID '.FROM_DUAL));
						$student_id = $student_id[1]['STUDENT_ID'];
					}
					while(count(DBGet(DBQuery("SELECT STUDENT_ID FROM STUDENTS WHERE STUDENT_ID='".$student_id."'"))));

				$sql = "INSERT INTO STUDENTS ";
				$fields = 'STUDENT_ID,';
				$values = "'".$student_id."',";

				$fields_RET = DBGet(DBQuery("SELECT ID,TYPE FROM CUSTOM_FIELDS ORDER BY SORT_ORDER"), array(), array('ID'));
				foreach($_REQUEST['students'] as $column=>$value)
				{
					if($column=='USERNAME' && $value)
						if(DBGet(DBQuery("SELECT STUDENT_ID FROM STUDENTS WHERE USERNAME='".$value."'")))
							$value = '';
					if(!empty($value) || $value=='0')
					{
						//modif Francois: check numeric fields
						if ($fields_RET[str_replace('CUSTOM_','',$column)][1]['TYPE'] == 'numeric' && $value!='' && !is_numeric($value))
						{
							$error[] = _('Please enter valid Numeric data.');
							continue;
						}
						
						$fields .= $column.',';
						if(!is_array($value))
						{
							//modif Francois: add password encryption
							if ($column!=='PASSWORD')
								$values .= "'".$value."',";
							else
							{
								$value = str_replace("''","'",$value);
								$values .= "'".encrypt_password($value)."',";
							}
						}
						else
						{
							$values .= "'||";
							foreach($value as $val)
							{
								if($val)
									$values .= $val.'||';
							}
							$values .= "',";
						}
					}
				}

				$sql .= '(' . mb_substr($fields,0,-1) . ') values(' . mb_substr($values,0,-1) . ')';
				DBQuery($sql);

				// create default food service account for this student
				$sql = "INSERT INTO FOOD_SERVICE_ACCOUNTS (ACCOUNT_ID,BALANCE,TRANSACTION_ID) values('".$student_id."','0.00','0')";
				DBQuery($sql);

				// associate with default food service account and assign other defaults
				$sql = "INSERT INTO FOOD_SERVICE_STUDENT_ACCOUNTS (STUDENT_ID,DISCOUNT,BARCODE,ACCOUNT_ID) values('".$student_id."','','','".$student_id."')";
				DBQuery($sql);


				$_SESSION['student_id'] = $_REQUEST['student_id'] = $student_id;

				//hook
				do_action('Students/Student.php|create_student');
			
			}
		}

		if (UserStudentID() && $_FILES['photo'])
		{
			$new_photo_file = FileUpload('photo', $StudentPicturesPath.UserSyear().'/', array('.jpg', '.jpeg'), 2, $error, '.jpg', UserStudentID());

			//hook
			do_action('Students/Student.php|upload_student_photo');
		}

	}

	if($_REQUEST['include']!= 'General_Info' && $_REQUEST['include']!= 'Address' && $_REQUEST['include']!= 'Medical' && $_REQUEST['include']!= 'Other_Info')
		if(!mb_strpos($_REQUEST['include'],'/'))
			include('modules/Students/includes/'.$_REQUEST['include'].'.inc.php');
		else
			include('modules/'.$_REQUEST['include'].'.inc.php');

	if ($error && !UserStudentID())
		$_REQUEST['student_id'] = 'new';

	unset($_REQUEST['modfunc']);
	// SHOULD THIS BE HERE???
	if(!UserStudentID())
		unset($_REQUEST['values']);
	unset($_SESSION['_REQUEST_vars']['modfunc']);
	unset($_SESSION['_REQUEST_vars']['values']);
}

if($_REQUEST['student_id']=='new')
{
	$_ROSARIO['HeaderIcon'] = 'modules/Students/icon.png';
	DrawHeader(_('Add a Student'));
}
else
	DrawHeader(ProgramTitle());

if (isset($error))
	echo ErrorMessage($error);

Search('student_id');

if(UserStudentID() || $_REQUEST['student_id']=='new')
{
	if(User('PROFILE')!='student')
		if(User('PROFILE_ID'))
			$can_use_RET = DBGet(DBQuery("SELECT MODNAME FROM PROFILE_EXCEPTIONS WHERE PROFILE_ID='".User('PROFILE_ID')."' AND CAN_USE='Y'"),array(),array('MODNAME'));
		else
			$can_use_RET = DBGet(DBQuery("SELECT MODNAME FROM STAFF_EXCEPTIONS WHERE USER_ID='".User('STAFF_ID')."' AND CAN_USE='Y'"),array(),array('MODNAME'));
	else
		$can_use_RET = DBGet(DBQuery("SELECT MODNAME FROM PROFILE_EXCEPTIONS WHERE PROFILE_ID='0' AND CAN_USE='Y'"),array(),array('MODNAME'));
//modif Francois: General_Info only for new student
	 //$categories_RET = DBGet(DBQuery("SELECT ID,TITLE,INCLUDE FROM STUDENT_FIELD_CATEGORIES ORDER BY SORT_ORDER,TITLE"));
	 $categories_RET = DBGet(DBQuery("SELECT ID,TITLE,INCLUDE FROM STUDENT_FIELD_CATEGORIES WHERE ".($_REQUEST['student_id']!='new'?'TRUE':'ID=\'1\'')." ORDER BY SORT_ORDER,TITLE"));

	if($_REQUEST['modfunc']!='delete' || $_REQUEST['delete_ok']=='1')
	{
		if($_REQUEST['student_id']!='new')
		{
			$sql = "SELECT s.STUDENT_ID,s.FIRST_NAME,s.LAST_NAME,s.MIDDLE_NAME,s.NAME_SUFFIX,s.USERNAME,s.PASSWORD,s.LAST_LOGIN,
			(SELECT SCHOOL_ID FROM STUDENT_ENROLLMENT WHERE SYEAR='".UserSyear()."' AND STUDENT_ID=s.STUDENT_ID ORDER BY START_DATE DESC,END_DATE DESC LIMIT 1) AS SCHOOL_ID,
			(SELECT GRADE_ID FROM STUDENT_ENROLLMENT WHERE SYEAR='".UserSyear()."' AND STUDENT_ID=s.STUDENT_ID ORDER BY START_DATE DESC,END_DATE DESC LIMIT 1) AS GRADE_ID 
			FROM STUDENTS s 
			WHERE s.STUDENT_ID='".UserStudentID()."'";
			
			$student = DBGet(DBQuery($sql));
			$student = $student[1];
			$school = DBGet(DBQuery("SELECT SCHOOL_ID,GRADE_ID FROM STUDENT_ENROLLMENT WHERE STUDENT_ID='".UserStudentID()."' AND SYEAR='".UserSyear()."' AND ('".DBDate()."' BETWEEN START_DATE AND END_DATE OR END_DATE IS NULL)"));
			
			echo '<FORM name="student" action="Modules.php?modname='.$_REQUEST['modname'].'&include='.$_REQUEST['include'].'&category_id='.$_REQUEST['category_id'].'&modfunc=update" method="POST" enctype="multipart/form-data">';
		}
		else
			echo '<FORM name="student" action="Modules.php?modname='.$_REQUEST['modname'].'&include='.$_REQUEST['include'].'&modfunc=update" method="POST" enctype="multipart/form-data">';

		if($_REQUEST['student_id']!='new')
			$name = $student['FIRST_NAME'].' '.$student['MIDDLE_NAME'].' '.$student['LAST_NAME'].' '.$student['NAME_SUFFIX'].' - '.$student['STUDENT_ID'];

		DrawHeader($name,SubmitButton(_('Save')));

		//hook
		do_action('Students/Student.php|header');

		foreach($categories_RET as $category)
		{
			if($can_use_RET['Students/Student.php&category_id='.$category['ID']])
			{
				if($category['ID']=='1')
					$include = 'General_Info';
				elseif($category['ID']=='3')
					$include = 'Address';
				elseif($category['ID']=='2')
					$include = 'Medical';
				elseif($category['ID']=='4')
					$include = 'Comments';
				elseif($category['INCLUDE'])
					$include = $category['INCLUDE'];
				else
					$include = 'Other_Info';

				$tabs[] = array('title'=>$category['TITLE'],'link'=>'Modules.php?modname='.$_REQUEST['modname'].'&include='.$include.'&category_id='.$category['ID']);
			}
		}

		$_ROSARIO['selected_tab'] = 'Modules.php?modname='.$_REQUEST['modname'].'&include='.$_REQUEST['include'];
		if($_REQUEST['category_id'])
			$_ROSARIO['selected_tab'] .= '&category_id='.$_REQUEST['category_id'];

		echo '<BR />';
		echo PopTable('header',$tabs,'width="100%"');
		$PopTable_opened = true;

		if ($can_use_RET['Students/Student.php&category_id='.$_REQUEST['category_id']])
		{
			if(!mb_strpos($_REQUEST['include'],'/'))
				include('modules/Students/includes/'.$_REQUEST['include'].'.inc.php');
			else
			{
				include('modules/'.$_REQUEST['include'].'.inc.php');
				$separator = '<HR>';
				include('modules/Students/includes/Other_Info.inc.php');
			}
		}
		echo PopTable('footer');
		echo '<span class="center">'.SubmitButton(_('Save')).'</span>';
		echo '</FORM>';
	}
	elseif ($can_use_RET['Students/Student.php&category_id='.$_REQUEST['category_id']])
		if(!mb_strpos($_REQUEST['include'],'/'))
			include('modules/Students/includes/'.$_REQUEST['include'].'.inc.php');
		else
		{
			include('modules/'.$_REQUEST['include'].'.inc.php');
			$separator = '<HR>';
			include('modules/Students/includes/Other_Info.inc.php');
		}
}
?>
