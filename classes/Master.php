<?php
require_once('../config.php');
Class Master extends DBConnection {
	private $settings;
	public function __construct(){
		global $_settings;
		$this->settings = $_settings;
		parent::__construct();
	}
	public function __destruct(){
		parent::__destruct();
	}
	function capture_err(){
		if(!$this->conn->error)
			return false;
		else{
			$resp['status'] = 'failed';
			$resp['error'] = $this->conn->error;
			if(isset($sql))
			$resp['sql'] = $sql;
			return json_encode($resp);
			exit;
		}
	}
	function save_appointment(){
		extract($_POST);
		$sched_set_qry = $this->conn->query("SELECT * FROM `schedule_settings`");
		$sched_set = array_column($sched_set_qry->fetch_all(MYSQLI_ASSOC),'meta_value','meta_field');
		$morning_start = date("Y-m-d ") . explode(',',$sched_set['morning_schedule'])[0];
		$morning_end = date("Y-m-d ") . explode(',',$sched_set['morning_schedule'])[1];
		$afternoon_start = date("Y-m-d ") . explode(',',$sched_set['afternoon_schedule'])[0];
		$afternoon_end = date("Y-m-d ") . explode(',',$sched_set['afternoon_schedule'])[1];
		$sched_time = date("Y-m-d ") . date("H:i",strtotime($date_sched));


		$doctor_query_settings = $this->conn->query("SELECT * FROM `doctor_settings` where id = '{$doctor_settings_id}' ");
		$sched_set_doctor = $doctor_query_settings->fetch_all(MYSQLI_ASSOC);
		$morning_start_doctor = date("Y-m-d ") . explode(',',$sched_set_doctor[0]['morning_schedule'])[0];
		$morning_end_doctor = date("Y-m-d ") . explode(',',$sched_set_doctor[0]['morning_schedule'])[1];

		



		if(!in_array(strtolower(date("l",strtotime($date_sched))),explode(',',strtolower($sched_set_doctor[0]['day_schedule'])))){
			$resp['status'] = 'failed';
			$resp['msg'] = "El día de la semana del programa seleccionado no es válido para el doctor '{$sched_set_doctor[0]['doctor']}'";
			return json_encode($resp);
			exit;
		}

		

		if(!( (strtotime($sched_time) >= strtotime($morning_start_doctor) && strtotime($sched_time) <= strtotime($morning_end_doctor))  )){
			$resp['status'] = 'failed';
			$resp['msg'] = "La hora de programación seleccionada no es válida para el horario del doctor '{$sched_set_doctor[0]['doctor']}'";
			return json_encode($resp);
			exit;
		}



		$date_query_settings = $this->conn->query("SELECT * FROM `appointments` WHERE doctor_settings_id = '{$doctor_settings_id}' ");
		$date_set_doctor = $date_query_settings->fetch_all(MYSQLI_ASSOC);
		foreach ($date_set_doctor as $valor) {

			$time = strtotime($valor['date_sched']);
			$tiempo_final = date("H:i", strtotime('+60 minutes', $time));
			$tiempo_inicial = $valor['date_sched'];
			if(strtotime($sched_time) >= strtotime($tiempo_inicial) && strtotime($sched_time) <= strtotime($tiempo_final)){
				$resp['status'] = 'failed';
				$resp['msg'] = "La fecha y hora de la programación seleccionada entra en conflicto con otra cita para el doctor '{$sched_set_doctor[0]['doctor']}'. Favor de checar el dashboard o intentar con otro doctor";
				return json_encode($resp);
				exit;
			}
		}

		if(empty($patient_id))
		$sql = "INSERT INTO `patient_list` set name = '{$name}'  ";
		else
		$sql = "UPDATE `patient_list` set name = '{$name}' where id = '{$id}'  ";
		echo "sql: $sql";
		$save_inv = $this->conn->query($sql);
		$this->capture_err();
		if($save_inv){
			$patient_id = (empty($patient_id))? $this->conn->insert_id : $patient_id;
			if(empty($id))
			$sql = "INSERT INTO `appointments` set date_sched = '{$date_sched}',patient_id = '{$patient_id}',`status` = '{$status}',`ailment` = '{$ailment}' , `doctor_settings_id` = '{$doctor_settings_id}'";
			else
			$sql = "UPDATE `appointments` set date_sched = '{$date_sched}',patient_id = '{$patient_id}',`status` = '{$status}',`ailment` = '{$ailment}', `doctor_settings_id` = '{$doctor_settings_id}' where id = '{$id}' ";

			$save_sched = $this->conn->query($sql);
			$this->capture_err();
			$data = "";
			foreach($_POST as $k=> $v){
				if(!in_array($k,array('lid','date_sched','status','ailment','doctor_settings_id'))){
					if(!empty($data)) $data .=", ";
					$data .= " ({$patient_id},'{$k}','{$v}')";
				}
			}
			$sql = "INSERT INTO `patient_meta` (patient_id,meta_field,meta_value) VALUES $data ";
			$this->conn->query("DELETE FROM `patient_meta` where patient_id = '{$patient_id}'");
			$save_meta = $this->conn->query($sql);
			$this->capture_err();
			if($save_sched && $save_meta){
				$resp['status'] = 'success';
				$resp['name'] = $name;
				$this->settings->set_flashdata('success',' Cita guardada con éxito');
			}else{
				$resp['status'] = 'failed';
				$resp['msg'] = "Hay un error al enviar los datos..";
			}

		}else{
			$resp['status'] = 'failed';
			$resp['msg'] = "Hay un error al enviar los datos..";
		}
		return json_encode($resp);
	}
	function multiple_action(){
		extract($_POST);
		if($_action != 'delete'){
			$stat_arr = array("pending"=>0,"confirmed"=>1,"cancelled"=>2);
			$status = $stat_arr[$_action];
			$sql = "UPDATE `appointments` set status = '{$status}' where patient_id in (".(implode(",",$ids)).") ";
			$process = $this->conn->query($sql);
			$this->capture_err();
		}else{
			$sql = "DELETE s.*,i.*,im.* from  `appointments` s inner join `patient_list` i on s.patient_id = i.id  inner join `patient_meta` im on im.patient_id = i.id where s.patient_id in (".(implode(",",$ids)).") ";
			$process = $this->conn->query($sql);
			$this->capture_err();
		}
		if($process){
			$resp['status'] = 'success';
			$act = $_action == 'delete' ? "Eliminado" : "Actualizado";
			$this->settings->set_flashdata("success","Cita generada exitósamente ".$act);
		}else{
			$resp['status'] = 'failed';
			$resp['error_sql'] = $sql;
		}
		return json_encode($resp);
	}
	function save_sched_settings(){
		$data = "";
		foreach($_POST as $k => $v){
			if(is_array($_POST[$k]))
			$v = implode(',',$_POST[$k]);
			if(!empty($data)) $data .= ",";
			$data .= " ('{$k}','{$v}') ";
		}
		$sql = "INSERT INTO `appointment_settings` (`meta_field`,`meta_value`) VALUES {$data}";
		if(!empty($data)){
			$this->conn->query("DELETE FROM `appointment_settings`");
			$this->capture_err();
		}
		$save = $this->conn->query($sql);
		if($save){
			$resp['status'] = 'success';
			$this->settings->set_flashdata('success',' la configuración de la cita se actualizó correctamente');
		}else{
			$resp['status'] = 'failed';
			$resp['err'] = $this->conn->error;
			$resp['msg'] = "Se produjo un error al ejecutar la consulta.";

		}
		return json_encode($resp);
	}
	
}

$Master = new Master();
$action = !isset($_GET['f']) ? 'none' : strtolower($_GET['f']);
$sysset = new SystemSettings();
switch ($action) {
	case 'save_appointment':
		echo $Master->save_appointment();
	break;
	case 'multiple_action':
		echo $Master->multiple_action();
	break;
	case 'save_sched_settings':
		echo $Master->save_sched_settings();
		break;
	default:
		// echo $sysset->index();
		break;
}