<?php
/* Thin sendMail() wrapper around the vendored PHPMailer (SMTP). */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'vendor/autoload.php';

function sendMail($to,$subject,$body){

$mail=new PHPMailer(true);

try{

$mail->isSMTP();

$mail->Host="smtp.gmail.com";

$mail->SMTPAuth=true;

$mail->Username="your_email@gmail.com";

$mail->Password="your_app_password";

$mail->SMTPSecure="tls";

$mail->Port=587;

$mail->setFrom("your_email@gmail.com","Student Violation System");

$mail->addAddress($to);

$mail->isHTML(true);

$mail->Subject=$subject;

$mail->Body=$body;

$mail->send();

return true;

}catch(Exception $e){

return false;

}

}
?>
