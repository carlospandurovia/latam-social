-- Datos minimos para ejecutar las pruebas de restriccion.
-- Reutilizable por todas las iteraciones. Vive en el repositorio a proposito:
-- las semillas en /tmp se pierden y las pruebas dejan de ser reproducibles.
SET NAMES utf8mb4;

INSERT INTO currencies (code,name,symbol,decimal_places,is_active) VALUES
 ('PEN','Sol peruano','S/',2,1),
 ('USD','Dolar estadounidense','$',2,1),
 ('COP','Peso colombiano','$',2,1);

INSERT INTO countries (iso2,iso3,numeric_code,name,phone_code,default_currency_code,timezone,is_active) VALUES
 ('PE','PER','604','Peru','+51','PEN','America/Lima',1),
 ('CO','COL','170','Colombia','+57','COP','America/Bogota',1),
 ('US','USA','840','Estados Unidos','+1','USD','America/New_York',1);

-- Contrasenas: hash bcrypt de un valor irrelevante, nunca texto plano ni
-- siquiera en una semilla de pruebas.
-- Contrasenas: un hash bcrypt sintetico, nunca texto plano ni en pruebas.
INSERT INTO users (uuid,name,user_type,email,password,status,created_at) VALUES
 (UUID(),'Operador Uno','internal','op1@ejemplo.test','$2y$12$0000000000000000000000u1SinValorRealNoEsUnaClave00',   'active',NOW(3)),
 (UUID(),'Operador Dos','internal','op2@ejemplo.test','$2y$12$0000000000000000000000u2SinValorRealNoEsUnaClave00',   'active',NOW(3)),
 (UUID(),'Aprobador',   'internal','aprob@ejemplo.test','$2y$12$0000000000000000000000u3SinValorRealNoEsUnaClave00','active',NOW(3));

INSERT INTO files (uuid,disk,path,original_name,mime_type,size_bytes,checksum_sha256,visibility,purpose,created_at) VALUES
 (UUID(),'local','pruebas/comprobante.pdf','comprobante.pdf','application/pdf',1024,REPEAT('a',64),'private','invoice',NOW(3));

INSERT INTO platform_brands (uuid,code,name,is_active,created_at) VALUES
 (UUID(),'LATAM','LATAM Social',1,NOW(3));

INSERT INTO legal_entities (uuid,platform_brand_id,code,legal_name,country_id,tax_id_type,tax_id_number,
   address_line1,city,default_currency_code,timezone,status,created_at)
 SELECT UUID(),pb.id,'CTS-PE','Soluciones Tecnologicas a Medida S.A.C.',c.id,'RUC','20603203896',
   'Por completar','Lima','PEN','America/Lima','active',NOW(3)
 FROM platform_brands pb, countries c WHERE pb.code='LATAM' AND c.iso2='PE';

INSERT INTO document_series (legal_entity_id,document_type,series,next_number,environment,is_active,created_at)
 SELECT id,'invoice','F001',1,'production',1,NOW(3) FROM legal_entities WHERE code='CTS-PE';

INSERT INTO client_organizations (uuid,commercial_name,client_code,country_id,status,created_at)
 SELECT UUID(),'Marca Demo S.A.','CLI-0001',id,'active',NOW(3) FROM countries WHERE iso2='PE';

INSERT INTO client_tax_profiles (client_organization_id,country_id,legal_name,tax_id_type,tax_id_number,
   address_line1,city,payment_term_days,valid_from,created_at)
 SELECT co.id,co.country_id,'Marca Demo S.A.','RUC','20123456789','Av. Demo 100','Lima',30,'2026-01-01',NOW(3)
 FROM client_organizations co WHERE co.client_code='CLI-0001';

INSERT INTO client_brands (uuid,client_organization_id,name,slug,status,created_at)
 SELECT UUID(),id,'Demo Brand','demo-brand','active',NOW(3) FROM client_organizations WHERE client_code='CLI-0001';

INSERT INTO creators (uuid,first_name,last_name,display_name,birth_date,email,country_id,
   document_country_code,document_type,document_number,preferred_currency_code,status,created_at)
 SELECT UUID(),'Ana','Torres','anatorres','1998-05-12','ana@ejemplo.test',id,'PE','DNI','40000001','PEN','active',NOW(3)
 FROM countries WHERE iso2='PE';
INSERT INTO creators (uuid,first_name,last_name,display_name,birth_date,email,country_id,
   document_country_code,document_type,document_number,preferred_currency_code,status,created_at)
 SELECT UUID(),'Luis','Vega','luisvega','1995-02-03','luis@ejemplo.test',id,'PE','DNI','40000002','PEN','active',NOW(3)
 FROM countries WHERE iso2='PE';

INSERT INTO creator_payment_methods (uuid,creator_id,owner_type,method_type,country_id,currency_code,
   bank_name,account_type,account_number_encrypted,account_number_masked,account_number_fingerprint,
   holder_name,holder_document_type,holder_document_number,status,verified_at,verified_by_user_id,
   eligible_from,is_default,created_at)
 SELECT UUID(),cr.id,'creator','bank_account',cr.country_id,'PEN',
   'BCP','savings','enc:xxxx','****4321',REPEAT('b',64),
   'Ana Torres','DNI','40000001','verified',NOW(3),(SELECT id FROM users LIMIT 1),
   NOW(3),1,NOW(3)
 FROM creators cr WHERE cr.display_name='anatorres';

INSERT INTO campaigns (uuid,code,name,client_organization_id,client_brand_id,currency_code,starts_on,ends_on,status,confirmed_at,created_at)
 SELECT UUID(),'CMP-0001','Campana Demo',co.id,cb.id,'PEN','2026-09-01','2026-09-30','in_progress',NOW(3),NOW(3)
 FROM client_organizations co JOIN client_brands cb ON cb.client_organization_id=co.id
 WHERE co.client_code='CLI-0001' LIMIT 1;

INSERT INTO campaign_creators (uuid,campaign_id,creator_id,currency_code,agreed_amount,status,invited_at,accepted_at,created_at)
 SELECT UUID(),ca.id,cr.id,'PEN',1500.0000,'accepted',NOW(3),NOW(3),NOW(3)
 FROM campaigns ca, creators cr WHERE ca.code='CMP-0001' AND cr.display_name='anatorres';
INSERT INTO campaign_creators (uuid,campaign_id,creator_id,currency_code,agreed_amount,status,invited_at,accepted_at,created_at)
 SELECT UUID(),ca.id,cr.id,'PEN',900.0000,'accepted',NOW(3),NOW(3),NOW(3)
 FROM campaigns ca, creators cr WHERE ca.code='CMP-0001' AND cr.display_name='luisvega';

-- Plataformas y formatos: los necesitan las pruebas de 2.12 (contenido).
INSERT INTO platforms (code,name,url_pattern,is_active,created_at) VALUES
 ('instagram','Instagram','https://www.instagram.com/p/{id}',1,NOW(3)),
 ('tiktok','TikTok','https://www.tiktok.com/@{user}/video/{id}',1,NOW(3));

INSERT INTO content_formats (platform_id,code,default_permanence_days,is_active,created_at)
 SELECT id,'reel',30,1,NOW(3) FROM platforms WHERE code='instagram';
INSERT INTO content_formats (platform_id,code,default_permanence_days,is_active,created_at)
 SELECT id,'story',1,1,NOW(3) FROM platforms WHERE code='instagram';

INSERT INTO campaign_requirements (campaign_id,content_format_id,quantity,deadline_offset_days,permanence_days,created_at)
 SELECT ca.id,cf.id,2,10,30,NOW(3)
 FROM campaigns ca, content_formats cf WHERE ca.code='CMP-0001' AND cf.code='reel';
